<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require dirname(__DIR__,3).'/vendor/autoload.php';

use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Persistence\PdoSchemaMigrator;
use MarketingOS\Identity\Persistence\PdoPersonStore;
use MarketingOS\Identity\Transport\MosEncryptedWireQuarantine;
use MarketingOS\Identity\Transport\MosMessengerJsonSerializer;
use MarketingOS\Identity\Runtime\MosDLQReviewJournal;

$db=mos_test_connection();
(new PdoSchemaMigrator($db))->migrate();
$w=WorkspaceId::generate();$other=WorkspaceId::generate();
(new PdoPersonStore($db))->createWorkspace($w);
(new PdoPersonStore($db))->createWorkspace($other);
$key=str_repeat('Q',32);
$body='malformed:private-test@example.invalid';
$q=$db->prepare('INSERT INTO mos_messenger_messages(queue_name,body,headers,created_at,available_at)
 VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
$q->execute(['mos_identity_'.(string)$w,$body,'{}']);
$id=(int)$db->lastInsertId();
$scanner=new MosEncryptedWireQuarantine($db,new MosMessengerJsonSerializer(),$key,'ci-key-v1');
if($scanner->scan($w,10)['quarantined']!==1)throw new RuntimeException('fixture scan failed');
$stmt=$db->prepare('SELECT payload_sha256,reason_code,sealed_payload
 FROM mos_messenger_wire_quarantine WHERE workspace_id=? AND source_message_id=?');
$stmt->execute([(string)$w,$id]);$evidence=$stmt->fetch(PDO::FETCH_ASSOC);
if(!is_array($evidence))throw new RuntimeException('fixture evidence missing');
$reviewerKey=sodium_crypto_sign_keypair();
$approverKey=sodium_crypto_sign_keypair();
$reviewerSecret=sodium_crypto_sign_secretkey($reviewerKey);
$approverSecret=sodium_crypto_sign_secretkey($approverKey);
$trust=[
 'reviewers'=>['reviewer-01'=>base64_encode(sodium_crypto_sign_publickey($reviewerKey))],
 'approvers'=>['approver-02'=>base64_encode(sodium_crypto_sign_publickey($approverKey))]
];
$dir=sys_get_temp_dir().'/mos-journal-'.bin2hex(random_bytes(8));
if(!mkdir($dir,0700,true))throw new RuntimeException('Cannot create protected log directory');
chmod($dir,0700);
$journal=new MosDLQReviewJournal($db,$dir);
$first=[
  'review_id'=>bin2hex(random_bytes(16)),
  'workspace_id'=>(string)$w,
  'case_id'=>$id,
  'key_id'=>'ci-key-v1',
  'ciphertext_sha256'=>$evidence['payload_sha256'],
  'reason_code'=>$evidence['reason_code'],
  'decision'=>'CONTINUE_HOLD',
  'reviewer_id'=>'reviewer-01',
  'approver_id'=>'approver-02'
];
function journalSign(array $claim,string $reviewer,string $approver):array {
    $msg=MosDLQReviewJournal::message($claim);
    return [
        'reviewer_sig'=>base64_encode(sodium_crypto_sign_detached($msg,$reviewer)),
        'approver_sig'=>base64_encode(sodium_crypto_sign_detached($msg,$approver))
    ];
}
$firstSig=journalSign($first,$reviewerSecret,$approverSecret);
$head=MosDLQReviewJournal::genesis();
$passed=0;$failed=0;
function proveJournal(string $name,Closure $fn):void {
    global $passed,$failed;
    try{$fn();echo "PASS $name\n";$passed++;}
    catch(Throwable $e){echo "FAIL $name: ".$e->getMessage()."\n";$failed++;}
}
function journalAssert(bool $okay):void {
    if(!$okay)throw new RuntimeException('assertion failed');
}
function journalDenied(Closure $f,string $error):void {
    $caught=false;
    try{$f();}catch(RuntimeException $e){$caught=$e->getMessage()===$error;}
    journalAssert($caught);
}
function journalCli(WorkspaceId $workspace,string $artifact,array $changes):array {
    $env=array_merge(getenv(),$changes);
    $p=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/dlq_record_dual_review.php',
        (string)$workspace,$artifact],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
    if(!is_resource($p))throw new RuntimeException('Failed to start CLI');
    fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($p),trim($stdout),trim($stderr)];
}
try {
    proveJournal('empty journal explicitly requires independent genesis witness',function()use($journal,$trust,$head){
        $r=$journal->inspect($trust,$head);
        journalAssert($r['entry_count']===0&&$r['decision']==='EMPTY_JOURNAL');
    });
    proveJournal('two different signatures authenticate one encrypted HOLD record',function()use($journal,$w,$first,$firstSig,$trust,$key,&$head){
        $r=$journal->append($w,$first,$firstSig,$trust,$key,$head);
        journalAssert($r['decision']==='RECORDED_NO_EFFECT'&&$r['entry_count']===1);
        $head=$r['head_hash'];
        journalAssert($journal->inspect($trust,$head)['entry_count']===1);
    });
    proveJournal('one signer cannot self-approve or reuse their key across roles',function()use($journal,$w,$first,$firstSig,$trust,$key,$head){
        $bad=$first;$bad['review_id']=bin2hex(random_bytes(16));$bad['approver_id']='reviewer-01';
        $same=$trust;$same['approvers']['reviewer-01']=$trust['reviewers']['reviewer-01'];
        journalDenied(fn()=>$journal->append($w,$bad,$firstSig,$same,$key,$head),'REVIEW_AUTHORITY_REFUSED');
    });
    proveJournal('forged, altered, or untrusted signatures cannot create journal entry',function()use($journal,$w,$first,$firstSig,$trust,$key,$head){
        $forged=$first;$forged['review_id']=bin2hex(random_bytes(16));
        journalDenied(fn()=>$journal->append($w,$forged,$firstSig,$trust,$key,$head),'REVIEW_SIGNATURE_INVALID');
        $bad=$firstSig;$bad['approver_sig']=base64_encode(str_repeat('X',64));
        journalDenied(fn()=>$journal->append($w,$first,$bad,$trust,$key,$head),'REVIEW_SIGNATURE_INVALID');
    });
    proveJournal('duplicate review ID and stale external witness are refused',function()use($journal,$w,$first,$firstSig,$trust,$key,$head){
        journalDenied(fn()=>$journal->append($w,$first,$firstSig,$trust,$key,$head),'REVIEW_DUPLICATE');
        journalDenied(fn()=>$journal->append($w,$first,$firstSig,$trust,$key,MosDLQReviewJournal::genesis()),'REVIEW_ANCHOR_MISMATCH');
    });
    proveJournal('altered ciphertext digest and wrong key are rejected before append',function()use($journal,$w,$first,$trust,$reviewerSecret,$approverSecret,$key,$head){
        $bad=$first;$bad['review_id']=bin2hex(random_bytes(16));
        $bad['ciphertext_sha256']=str_repeat('f',64);
        journalDenied(fn()=>$journal->append($w,$bad,journalSign($bad,$reviewerSecret,$approverSecret),$trust,$key,$head),'REVIEW_EVIDENCE_MISMATCH');
        $fresh=$first;$fresh['review_id']=bin2hex(random_bytes(16));
        journalDenied(fn()=>$journal->append($w,$fresh,journalSign($fresh,$reviewerSecret,$approverSecret),$trust,str_repeat('Z',32),$head),'ARCHIVE_AUTHENTICATION_FAILED');
    });
    proveJournal('Workspace mismatch never permits cross-tenant review',function()use($journal,$w,$other,$first,$trust,$reviewerSecret,$approverSecret,$key,$head){
        $bad=$first;$bad['workspace_id']=(string)$other;$bad['review_id']=bin2hex(random_bytes(16));
        journalDenied(fn()=>$journal->append($w,$bad,journalSign($bad,$reviewerSecret,$approverSecret),$trust,$key,$head),'REVIEW_WORKSPACE_MISMATCH');
        journalDenied(fn()=>$journal->append($other,$bad,journalSign($bad,$reviewerSecret,$approverSecret),$trust,$key,$head),'REVIEW_EVIDENCE_MISMATCH');
    });
    proveJournal('second independently signed escalation chains on previous committed head',function()use($journal,$w,$first,$trust,$reviewerSecret,$approverSecret,$key,&$head){
        $second=$first;$second['review_id']=bin2hex(random_bytes(16));
        $second['decision']='ESCALATE';
        $r=$journal->append($w,$second,journalSign($second,$reviewerSecret,$approverSecret),$trust,$key,$head);
        journalAssert($r['entry_count']===2&&$r['head_hash']!==$head);
        $head=$r['head_hash'];
        journalAssert($journal->inspect($trust,$head)['entry_count']===2);
    });
    proveJournal('modified signed JSON line is detected by verification',function()use($journal,$trust,$head,$dir){
        $p=$dir.'/dlq-review-v1.jsonl';$saved=file_get_contents($p);
        journalAssert(is_string($saved));
        file_put_contents($p,str_replace('CONTINUE_HOLD','REJECT_REPLAY',$saved));
        try{journalDenied(fn()=>$journal->inspect($trust,$head),'REVIEW_JOURNAL_CORRUPT');}
        finally{file_put_contents($p,$saved);}
    });
    proveJournal('full journal truncation is detectable only using separately saved witness',function()use($journal,$trust,$head,$dir){
        $p=$dir.'/dlq-review-v1.jsonl';$saved=file_get_contents($p);
        journalAssert(is_string($saved));
        file_put_contents($p,'');
        try{journalDenied(fn()=>$journal->inspect($trust,$head),'REVIEW_ANCHOR_MISMATCH');}
        finally{file_put_contents($p,$saved);}
    });
    proveJournal('invalid reason or free-text PII rejected by canonical claim policy',function()use($first){
        $bad=$first;$bad['reason_code']='alice@example.invalid';
        journalDenied(fn()=>MosDLQReviewJournal::message($bad),'REVIEW_CLAIM_INVALID');
    });
    proveJournal('CLI disabled by default, no append or external effects',function()use($w,$first,$firstSig,$dir,$head){
        $p=$dir.'/signed-case.json';
        file_put_contents($p,json_encode(['claim'=>$first,'signatures'=>$firstSig],JSON_THROW_ON_ERROR));
        chmod($p,0600);
        [$exit,$out,$err]=journalCli($w,$p,['MOS_REVIEW_RECORD_ENABLED'=>'0']);
        journalAssert($exit===2&&$out===''&&str_contains($err,'REVIEW_DISABLED'));
    });
    proveJournal('CLI records signed no-effect recommendation with protected trust manifest',function()use($w,$first,$trust,$reviewerSecret,$approverSecret,$dir,&$head,$journal){
        $third=$first;$third['review_id']=bin2hex(random_bytes(16));$third['decision']='REJECT_REPLAY';
        $artifact=$dir.'/signed-third.json';
        $manifest=$dir.'/public-roles.json';
        file_put_contents($artifact,json_encode(['claim'=>$third,'signatures'=>journalSign($third,$reviewerSecret,$approverSecret)],JSON_THROW_ON_ERROR));
        file_put_contents($manifest,json_encode($trust,JSON_THROW_ON_ERROR));
        chmod($artifact,0600);chmod($manifest,0600);
        [$exit,$out,$err]=journalCli($w,$artifact,[
            'MOS_REVIEW_RECORD_ENABLED'=>'1',
            'MOS_QUEUE_MODE'=>'SHADOW',
            'MOS_REVIEW_TRUST_FILE'=>$manifest,
            'MOS_REVIEW_JOURNAL_DIR'=>$dir,
            'MOS_REVIEW_EXPECTED_HEAD'=>$head,
            'MOS_DLQ_REVIEW_KEY_B64'=>base64_encode(str_repeat('Q',32))
        ]);
        journalAssert($exit===0&&$err==='');
        $receipt=json_decode($out,true,20,JSON_THROW_ON_ERROR);
        journalAssert($receipt['result']['entry_count']===3);
        journalAssert($receipt['result']['decision']==='RECORDED_NO_EFFECT');
        journalAssert($receipt['permission']==='NONE'&&$receipt['redrive']===false&&$receipt['external_effect']===false);
        $head=$receipt['result']['head_hash'];
        journalAssert($journal->inspect($trust,$head)['entry_count']===3);
    });
    proveJournal('separate offline signer validates protected key, identity and explicit opt-in',function()use($first,$dir,$reviewerSecret,$reviewerKey){
        $claim=$first;$claim['review_id']=bin2hex(random_bytes(16));
        $claimPath=$dir.'/offline-unsigned.json';$secretPath=$dir.'/reviewer-private.key';
        file_put_contents($claimPath,json_encode($claim,JSON_THROW_ON_ERROR));
        file_put_contents($secretPath,base64_encode($reviewerSecret)."\n");
        chmod($claimPath,0600);chmod($secretPath,0600);
        $invoke=function(array $changes)use($claimPath,$secretPath):array {
            $env=array_merge(getenv(),[
                'MOS_REVIEW_SIGN_ENABLED'=>'1',
                'MOS_REVIEW_SIGNER_ID'=>'reviewer-01',
                'MOS_REVIEW_SIGNING_KEY_FILE'=>$secretPath
            ],$changes);
            $proc=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/dlq_sign_review_offline.php',
                $claimPath,'REVIEWER'],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
            if(!is_resource($proc))throw new RuntimeException('Sign process start failed');
            fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
            fclose($pipes[1]);fclose($pipes[2]);
            return [proc_close($proc),trim($out),trim($err)];
        };
        [$exit,$out,$err]=$invoke(['MOS_REVIEW_SIGN_ENABLED'=>'0']);
        journalAssert($exit===2&&$out===''&&str_contains($err,'SIGN_DISABLED'));
        [$exit,$out,$err]=$invoke(['MOS_REVIEW_SIGNER_ID'=>'approver-02']);
        journalAssert($exit===2&&$out===''&&str_contains($err,'SIGN_IDENTITY_MISMATCH'));
        [$exit,$out,$err]=$invoke([]);
        journalAssert($exit===0&&$err==='');
        $r=json_decode($out,true,16,JSON_THROW_ON_ERROR);
        journalAssert($r['role']==='REVIEWER'&&$r['signer_id']==='reviewer-01');
        journalAssert(sodium_crypto_sign_verify_detached(base64_decode($r['signature'],true),
            MosDLQReviewJournal::message($claim),sodium_crypto_sign_publickey($reviewerKey)));
        journalAssert(!str_contains($out,base64_encode($reviewerSecret)));
    });
    proveJournal('all reviewed ciphertext stays quarantined and is never replayed',function()use($db,$w,$id,$evidence,$body){
        $q=$db->prepare('SELECT reason_code,payload_sha256,sealed_payload FROM mos_messenger_wire_quarantine WHERE workspace_id=? AND source_message_id=?');
        $q->execute([(string)$w,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        journalAssert($row['payload_sha256']===$evidence['payload_sha256'] && $row['sealed_payload']===$evidence['sealed_payload']);
        journalAssert(!str_contains($row['sealed_payload'],$body));
        $q=$db->prepare('SELECT COUNT(*) FROM mos_messenger_messages WHERE id=?');
        $q->execute([$id]);journalAssert((int)$q->fetchColumn()===0);
    });
} finally {
    foreach(glob($dir.'/*') as $file)@unlink($file);
    @rmdir($dir);
}
echo "C4-07B3B3B3A JOURNAL $passed passed, $failed failed\n";
exit($failed===0?0:1);
