<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require dirname(__DIR__,3).'/vendor/autoload.php';

use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Persistence\PdoSchemaMigrator;
use MarketingOS\Identity\Persistence\PdoPersonStore;
use MarketingOS\Identity\Runtime\MosShadowReadinessPreflight;
use MarketingOS\Identity\Transport\MosEncryptedWireQuarantine;
use MarketingOS\Identity\Transport\MosMessengerJsonSerializer;

$db=mos_test_connection();
(new PdoSchemaMigrator($db))->migrate();
$a=WorkspaceId::generate();$b=WorkspaceId::generate();
(new PdoPersonStore($db))->createWorkspace($a);
(new PdoPersonStore($db))->createWorkspace($b);
$old=str_repeat('a',32);$active=str_repeat('b',32);
$pre=new MosShadowReadinessPreflight($db);
$passed=0;$failed=0;
function checkPreflight(string $name, Closure $f):void {
    global $passed,$failed;
    try{$f();echo "PASS $name\n";$passed++;}
    catch(Throwable $e){echo "FAIL $name: ".$e->getMessage()."\n";$failed++;}
}
function requirePreflight(bool $yes):void {if(!$yes)throw new RuntimeException('assertion failed');}
function seedReadinessWire(PDO $db,WorkspaceId $w,string $text):int{
    $q=$db->prepare('INSERT INTO mos_messenger_messages (queue_name,body,headers,created_at,available_at) VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $q->execute(['mos_identity_'.(string)$w,$text,'{}']);
    return (int)$db->lastInsertId();
}
function preflightCli(WorkspaceId $w,array $opts,array $env=[]):array{
    $cmd=array_merge([PHP_BINARY,dirname(__DIR__).'/bin/shadow_readiness_preflight.php',(string)$w],$opts);
    $p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),$env));
    if(!is_resource($p))throw new RuntimeException('cannot start CLI');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),trim($out),trim($err)];
}
function preflightEnv(string $key):array{
    return ['MOS_PREFLIGHT_ENABLED'=>'1','MOS_QUEUE_MODE'=>'SHADOW','MOS_BRIDGE_MODE'=>'SHADOW',
       'MOS_SNAPSHOT_HMAC_KEY'=>str_repeat('x',32),'MOS_DLQ_KEY_B64'=>base64_encode($key)];
}
checkPreflight('complete checksummed schema and empty workspace are local-only PASS',function()use($pre,$a,$active){
    $r=$pre->assess($a,'active-v2',$active);
    requirePreflight($r['decision']==='LOCAL_PREFLIGHT_PASS'&&$r['schema_versions_verified']===6);
    requirePreflight($r['scope']==='LOCAL_READ_ONLY_SHADOW_NOT_DEPLOYMENT');
    requirePreflight($r['queue_depth']===0&&$r['wire_quarantine_count']===0);
});
checkPreflight('reject invalid key IDs and page limits before database inspection',function()use($pre,$a,$active){
    foreach([['bad/key',$active,100,10],['active-v2',$active,101,10],['active-v2','short',100,10],['active-v2',$active,100,11]] as $case){
        $caught=false;try{$pre->assess($a,...$case);}catch(RuntimeException $e){$caught=true;}
        requirePreflight($caught);
    }
});
checkPreflight('reject missing Workspace without showing any contact data',function()use($pre,$active){
    $caught=false;try{$pre->assess(WorkspaceId::generate(),'active-v2',$active);}catch(RuntimeException $e){$caught=$e->getMessage()==='PREFLIGHT_UNKNOWN_WORKSPACE';}
    requirePreflight($caught);
});
checkPreflight('archive one malformed wire under old key while keeping other tenant clean',function()use($db,$a,$b,$old,$pre,$active){
    seedReadinessWire($db,$a,'bad JSON alice@example.invalid');
    $sealed=new MosEncryptedWireQuarantine($db,new MosMessengerJsonSerializer(),$old,'old-v1');
    requirePreflight($sealed->scan($a,10)['quarantined']===1);
    $x=$pre->assess($a,'active-v2',$active);
    $y=$pre->assess($b,'active-v2',$active);
    requirePreflight($x['decision']==='REVIEW_REQUIRED'&&$x['other_key_archive_count']===1);
    requirePreflight(in_array('ARCHIVES_WITH_NONACTIVE_KEYS',$x['review_reasons'],true));
    requirePreflight($y['decision']==='LOCAL_PREFLIGHT_PASS'&&$y['wire_quarantine_count']===0);
    requirePreflight(!str_contains(json_encode($x),'alice@example.invalid'));
});
checkPreflight('active key authenticates archived bytes, but review is still required',function()use($pre,$a,$old){
    $r=$pre->assess($a,'old-v1',$old);
    requirePreflight($r['active_archive_verified']===1&&$r['active_archive_complete']);
    requirePreflight($r['decision']==='REVIEW_REQUIRED');
    requirePreflight(in_array('WIRE_QUARANTINE_REVIEW_REQUIRED',$r['review_reasons'],true));
});
checkPreflight('tampered active encrypted wire blocks instead of claiming readiness',function()use($db,$pre,$a,$old){
    $q=$db->prepare('SELECT source_message_id,sealed_payload FROM mos_messenger_wire_quarantine WHERE workspace_id=? LIMIT 1');
    $q->execute([(string)$a]);$row=$q->fetch(PDO::FETCH_ASSOC);
    $tampered=$row['sealed_payload'];$tampered[0]=chr(ord($tampered[0])^1);
    $u=$db->prepare('UPDATE mos_messenger_wire_quarantine SET sealed_payload=? WHERE workspace_id=? AND source_message_id=?');
    $u->execute([$tampered,(string)$a,$row['source_message_id']]);
    try{
        $caught=false;
        try{$pre->assess($a,'old-v1',$old);}catch(RuntimeException $e){$caught=$e->getMessage()==='ARCHIVE_AUTHENTICATION_FAILED';}
        requirePreflight($caught);
    }finally{$u->execute([$row['sealed_payload'],(string)$a,$row['source_message_id']]);}
});
checkPreflight('bounded page verification never claims unvisited archives authenticated',function()use($pre,$db,$a,$old){
    for($i=0;$i<2;$i++)seedReadinessWire($db,$a,'broken'.$i);
    (new MosEncryptedWireQuarantine($db,new MosMessengerJsonSerializer(),$old,'old-v1'))->scan($a,10);
    $r=$pre->assess($a,'old-v1',$old,1,1);
    requirePreflight($r['active_archive_verified']===1&&!$r['active_archive_complete']);
    requirePreflight(in_array('ACTIVE_ARCHIVE_VERIFICATION_INCOMPLETE',$r['review_reasons'],true));
});
checkPreflight('oversized queue wire blocks safe supervision but remains untouched',function()use($pre,$db,$b,$active){
    $id=seedReadinessWire($db,$b,str_repeat('X',262145));
    $r=$pre->assess($b,'active-v2',$active);
    requirePreflight($r['decision']==='REVIEW_REQUIRED'&&$r['oversized_queue_count']===1);
    requirePreflight(in_array('OVERSIZED_QUEUE_WIRE_BLOCKS_SUPERVISION',$r['review_reasons'],true));
    $q=$db->prepare('SELECT COUNT(*) FROM mos_messenger_messages WHERE id=?');$q->execute([$id]);
    requirePreflight((int)$q->fetchColumn()===1);
    $db->prepare('DELETE FROM mos_messenger_messages WHERE id=?')->execute([$id]);
});
checkPreflight('migration checksum tamper refuses readiness with no schema mutation',function()use($pre,$db,$b,$active){
    $db->exec("UPDATE mos_schema_migration SET checksum=REPEAT('0',64) WHERE version='006_encrypted_wire_quarantine'");
    try{
        $caught=false;try{$pre->assess($b,'active-v2',$active);}catch(RuntimeException $e){$caught=$e->getMessage()==='PREFLIGHT_SCHEMA_CHECKSUM_MISMATCH';}
        requirePreflight($caught);
    }finally{
        $hash=hash_file('sha256',dirname(__DIR__).'/migrations/006_encrypted_wire_quarantine.sql');
        $db->prepare("UPDATE mos_schema_migration SET checksum=? WHERE version='006_encrypted_wire_quarantine'")->execute([$hash]);
    }
});
checkPreflight('CLI denies operation by default or without two independent secrets',function()use($b,$active){
    [$c,$o,$e]=preflightCli($b,['active-v2','100'],array_merge(preflightEnv($active),['MOS_PREFLIGHT_ENABLED'=>'0']));
    requirePreflight($c===2&&$o===''&&str_contains($e,'DISABLED'));
    [$c,$o,$e]=preflightCli($b,['active-v2','100'],array_merge(preflightEnv($active),['MOS_SNAPSHOT_HMAC_KEY'=>'short']));
    requirePreflight($c===2&&$o===''&&str_contains($e,'SOURCE_KEY_MISSING'));
});
checkPreflight('CLI returns PII-free PASS receipt for scoped empty tenant',function()use($b,$active){
    [$c,$o,$e]=preflightCli($b,['active-v2','100'],preflightEnv($active));
    requirePreflight($c===0&&$e==='');$r=json_decode($o,true,16,JSON_THROW_ON_ERROR);
    requirePreflight($r['decision']==='LOCAL_PREFLIGHT_PASS');
    requirePreflight(!str_contains($o,'@example.invalid'));
});
checkPreflight('CLI returns distinct review-required exit without scheduling workers',function()use($a,$old){
    [$c,$o,$e]=preflightCli($a,['old-v1','100'],preflightEnv($old));
    requirePreflight($c===3&&$e==='');$r=json_decode($o,true,16,JSON_THROW_ON_ERROR);
    requirePreflight($r['decision']==='REVIEW_REQUIRED');
});
echo "C4-07B3B1 READINESS $passed passed, $failed failed\n";
exit($failed===0?0:1);
