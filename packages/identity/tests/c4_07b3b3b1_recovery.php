<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require dirname(__DIR__,3).'/vendor/autoload.php';

use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Persistence\PdoSchemaMigrator;
use MarketingOS\Identity\Persistence\PdoPersonStore;
use MarketingOS\Identity\Runtime\MosRestoredArchiveVerifier;
use MarketingOS\Identity\Transport\MosEncryptedWireQuarantine;
use MarketingOS\Identity\Transport\MosMessengerJsonSerializer;

if(getenv('MOS_CI_ALLOW_RESTORE_DRILL')!=='1' ||
   getenv('MOS_TEST_DSN')!=='mysql:host=127.0.0.1;port=3306;dbname=mautic_ci;charset=utf8mb4'||
   getenv('MOS_CI_ROOT_PASSWORD')!=='ci-only-root-password'){
    fwrite(STDERR,"RESTORE DRILL DISABLED: isolated MariaDB CI only\n");exit(2);
}
$source=mos_test_connection();
(new PdoSchemaMigrator($source))->migrate();
$a=WorkspaceId::generate();$b=WorkspaceId::generate();$empty=WorkspaceId::generate();
$sourceStore=new PdoPersonStore($source);
foreach([$a,$b,$empty] as $w)$sourceStore->createWorkspace($w);
$old=str_repeat('A',32);$new=str_repeat('B',32);
function seedRestoreWire(PDO $db,WorkspaceId $w,string $body):int {
    $s=$db->prepare('INSERT INTO mos_messenger_messages
      (queue_name,body,headers,created_at,available_at)
      VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $s->execute(['mos_identity_'.(string)$w,$body,'{}']);
    return (int)$db->lastInsertId();
}
$oldIds=[];
for($i=0;$i<2;$i++)$oldIds[]=seedRestoreWire($source,$a,'bad-wire-old-'.$i.'-secret@example.invalid');
$codec=new MosMessengerJsonSerializer();
$scannerOld=new MosEncryptedWireQuarantine($source,$codec,$old,'old-v1');
$scannerNew=new MosEncryptedWireQuarantine($source,$codec,$new,'new-v2');
if($scannerOld->scan($a,20)['quarantined']!==2)throw new RuntimeException('Initial scan failed');
$newId=seedRestoreWire($source,$a,'bad-wire-new-private@example.invalid');
if($scannerNew->scan($a,20)['quarantined']!==1)throw new RuntimeException('Second scan failed');
$bId=seedRestoreWire($source,$b,'bad-wire-tenant-b@example.invalid');
if($scannerOld->scan($b,20)['quarantined']!==1)throw new RuntimeException('Tenant B scan failed');

// Disposable second database is created independently and receives a deliberate
// exact encrypted-archive copy. This is NOT mysqldump or whole-server recovery.
$root=new PDO((string)getenv('MOS_TEST_DSN'),'root',(string)getenv('MOS_CI_ROOT_PASSWORD'),[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false
]);
$root->exec('CREATE DATABASE IF NOT EXISTS mautic_restore_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$restore=new PDO('mysql:host=127.0.0.1;port=3306;dbname=mautic_restore_ci;charset=utf8mb4',
    'root',(string)getenv('MOS_CI_ROOT_PASSWORD'),[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
(new PdoSchemaMigrator($restore))->migrate();
$restoreStore=new PdoPersonStore($restore);
foreach([$a,$b,$empty] as $w)$restoreStore->createWorkspace($w);
$columns=['workspace_id','source_message_id','queue_name','key_id','reason_code',
          'payload_sha256','sealed_nonce','sealed_payload','byte_count','quarantined_at'];
function restoredCopy(PDO $from,PDO $to,array $columns,WorkspaceId $w):void {
    $select=$from->prepare('SELECT '.implode(',',$columns).'
        FROM mos_messenger_wire_quarantine WHERE workspace_id=? ORDER BY source_message_id');
    $select->execute([(string)$w]);
    $sql='INSERT INTO mos_messenger_wire_quarantine ('.implode(',',$columns).
         ') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';
    $insert=$to->prepare($sql);
    foreach($select->fetchAll(PDO::FETCH_ASSOC) as $r) {
        foreach($columns as $i=>$name){
            $insert->bindValue($i+1,$r[$name],
                in_array($name,['sealed_nonce','sealed_payload'],true)?PDO::PARAM_LOB:PDO::PARAM_STR);
        }
        $insert->execute();
    }
}
restoredCopy($source,$restore,$columns,$a);
restoredCopy($source,$restore,$columns,$b);
$verifier=new MosRestoredArchiveVerifier($source,$restore);
$keys=['old-v1'=>$old,'new-v2'=>$new];
$passed=0;$failed=0;
function checkRestore(string $label,Closure $check):void{
    global $passed,$failed;
    try{$check();echo "PASS $label\n";$passed++;}
    catch(Throwable $e){echo "FAIL $label: ".$e->getMessage()."\n";$failed++;}
}
function assertRestore(bool $yes):void{if(!$yes)throw new RuntimeException('assertion');}
function expectedRefusal(Closure $f,string $reason):void{
    $caught=false;try{$f();}catch(RuntimeException $e){$caught=$e->getMessage()===$reason;}
    assertRestore($caught);
}
function reviewCli(WorkspaceId $w,string $keyId,string $key,array $extra=[]):array{
    $env=array_merge(getenv(),[
        'MOS_DLQ_REVIEW_ENABLED'=>'1',
        'MOS_QUEUE_MODE'=>'SHADOW',
        'MOS_DLQ_REVIEW_KEY_B64'=>base64_encode($key)
    ],$extra);
    $proc=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/dlq_review_queue.php',
       (string)$w,$keyId,'1','0'],
       [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
    if(!is_resource($proc))throw new RuntimeException('CLI spawn');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);
    $err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($proc),trim($out),trim($err)];
}
checkRestore('source and restored databases are separate real MariaDB schemas',function()use($source,$restore){
    assertRestore($source->query('SELECT DATABASE()')->fetchColumn()==='mautic_ci');
    assertRestore($restore->query('SELECT DATABASE()')->fetchColumn()==='mautic_restore_ci');
});
checkRestore('bounded proof authenticates encrypted bytes and marks incomplete first page',function()use($verifier,$a,$keys,$oldIds){
    $r=$verifier->verifyPage($a,$keys,1,0);
    assertRestore($r['decision']==='PAGE_AUTHENTICATED'&&$r['verified']===1);
    assertRestore($r['next_cursor']===$oldIds[0]&&$r['has_more']);
});
checkRestore('complete multi-key restored archive passes only after all pages',function()use($verifier,$a,$keys){
    $cursor=0;$total=0;$pages=0;
    do{
        $r=$verifier->verifyPage($a,$keys,1,$cursor);
        $total+=$r['verified'];$cursor=$r['next_cursor'];$pages++;
    }while($r['has_more']&&$pages<=10);
    assertRestore($total===3&&$pages===3&&!$r['has_more']);
});
checkRestore('other Workspace independently authenticates retained old-key archive',function()use($verifier,$b,$keys){
    $r=$verifier->verifyPage($b,$keys);
    assertRestore($r['verified']===1&&!$r['has_more']);
});
checkRestore('empty source and restored archives are explicitly NOT backup proof',function()use($verifier,$empty,$keys){
    $r=$verifier->verifyPage($empty,$keys);
    assertRestore($r['verified']===0&&$r['decision']==='EMPTY_ARCHIVE_NOT_BACKUP_PROOF');
});
checkRestore('missing historical key refuses incomplete recovery',function()use($verifier,$a,$new){
    expectedRefusal(fn()=>$verifier->verifyPage($a,['new-v2'=>$new]),'RESTORE_KEYS_MISSING');
});
checkRestore('wrong historical key refuses authenticated match even when bytes are identical',function()use($verifier,$a,$keys){
    $wrong=$keys;$wrong['old-v1']=str_repeat('Z',32);
    expectedRefusal(fn()=>$verifier->verifyPage($a,$wrong),'RESTORE_AUTH_FAILED');
});
checkRestore('same source and restore DB refuses fake recovery evidence',function()use($source,$a,$keys){
    expectedRefusal(fn()=>(new MosRestoredArchiveVerifier($source,$source))->verifyPage($a,$keys),'RESTORE_NOT_ISOLATED');
});
checkRestore('tampered ciphertext in restored DB causes divergence, never green receipt',function()use($restore,$verifier,$a,$keys,$oldIds){
    $q=$restore->prepare('SELECT sealed_payload FROM mos_messenger_wire_quarantine WHERE workspace_id=? AND source_message_id=?');
    $q->execute([(string)$a,$oldIds[0]]);$saved=(string)$q->fetchColumn();
    $bad=$saved;$bad[0]=chr(ord($bad[0])^1);
    $u=$restore->prepare('UPDATE mos_messenger_wire_quarantine SET sealed_payload=? WHERE workspace_id=? AND source_message_id=?');
    $u->execute([$bad,(string)$a,$oldIds[0]]);
    try{expectedRefusal(fn()=>$verifier->verifyPage($a,$keys),'RESTORE_DIVERGED');}
    finally{$u->execute([$saved,(string)$a,$oldIds[0]]);}
});
checkRestore('missing restored row causes explicit divergence',function()use($restore,$verifier,$a,$keys,$columns,$source,$newId){
    $d=$restore->prepare('DELETE FROM mos_messenger_wire_quarantine WHERE workspace_id=? AND source_message_id=?');
    $d->execute([(string)$a,$newId]);
    try{expectedRefusal(fn()=>$verifier->verifyPage($a,$keys),'RESTORE_DIVERGED');}
    finally{
        $s=$source->prepare('SELECT '.implode(',',$columns).' FROM mos_messenger_wire_quarantine WHERE workspace_id=? AND source_message_id=?');
        $s->execute([(string)$a,$newId]);$row=$s->fetch(PDO::FETCH_ASSOC);
        $i=$restore->prepare('INSERT INTO mos_messenger_wire_quarantine ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
        foreach($columns as $idx=>$col)$i->bindValue($idx+1,$row[$col],in_array($col,['sealed_nonce','sealed_payload'],true)?PDO::PARAM_LOB:PDO::PARAM_STR);
        $i->execute();
    }
});
checkRestore('metadata/key-ID change is treated as divergent even with unchanged ciphertext',function()use($restore,$verifier,$a,$keys,$newId){
    $u=$restore->prepare('UPDATE mos_messenger_wire_quarantine SET reason_code=? WHERE workspace_id=? AND source_message_id=?');
    $u->execute(['UNKNOWN_REASON',(string)$a,$newId]);
    try{expectedRefusal(fn()=>$verifier->verifyPage($a,$keys),'RESTORE_DIVERGED');}
    finally{$u->execute(['INVALID_WIRE_ENVELOPE',(string)$a,$newId]);}
});
checkRestore('out-of-range cursor and huge page are refused',function()use($verifier,$a,$keys){
    expectedRefusal(fn()=>$verifier->verifyPage($a,$keys,101),'RESTORE_BOUNDS_INVALID');
    expectedRefusal(fn()=>$verifier->verifyPage($a,$keys,1,-1),'RESTORE_BOUNDS_INVALID');
});
checkRestore('review CLI is off by default and leaves archived bytes intact',function()use($a,$old,$source){
    $before=(int)$source->query('SELECT COUNT(*) FROM mos_messenger_wire_quarantine')->fetchColumn();
    [$exit,$out,$err]=reviewCli($a,'old-v1',$old,['MOS_DLQ_REVIEW_ENABLED'=>'0']);
    assertRestore($exit===2&&$out===''&&str_contains($err,'REVIEW_DISABLED'));
    assertRestore((int)$source->query('SELECT COUNT(*) FROM mos_messenger_wire_quarantine')->fetchColumn()===$before);
});
checkRestore('authenticated review returns only ciphertext digest, case ID and HOLD decision',function()use($a,$old,$source){
    $before=(int)$source->query('SELECT COUNT(*) FROM mos_messenger_wire_quarantine')->fetchColumn();
    [$exit,$out,$err]=reviewCli($a,'old-v1',$old);
    assertRestore($exit===0&&$err==='');
    $receipt=json_decode($out,true,16,JSON_THROW_ON_ERROR);
    assertRestore($receipt['decision']==='HOLD_FOR_OPERATOR'&&$receipt['review_authority']==='NONE');
    assertRestore($receipt['verified']===1&&$receipt['has_more']&&$receipt['other_key_archive_count']===1);
    assertRestore($receipt['cases'][0]['disposition']==='HOLD_FOR_OPERATOR');
    assertRestore(!$receipt['auto_redrive']&&!str_contains($out,'@example.invalid'));
    assertRestore((int)$source->query('SELECT COUNT(*) FROM mos_messenger_wire_quarantine')->fetchColumn()===$before);
});
checkRestore('review refuses wrong cryptographic key without partial case output',function()use($a){
    [$exit,$out,$err]=reviewCli($a,'old-v1',str_repeat('Z',32));
    assertRestore($exit===2&&$out===''&&str_contains($err,'ARCHIVE_AUTHENTICATION_FAILED'));
});
checkRestore('restored archive remains byte-identical after read-only review and denial tests',function()use($verifier,$a,$keys){
    $r=$verifier->verifyPage($a,$keys,100);
    assertRestore($r['verified']===3&&!$r['has_more']);
});
echo "C4-07B3B3B1 RECOVERY $passed passed, $failed failed\n";
exit($failed===0?0:1);
