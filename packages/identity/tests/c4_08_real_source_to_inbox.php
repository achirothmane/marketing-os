<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Persistence\PdoPersonStore;
use MarketingOS\Identity\Persistence\PdoSchemaMigrator;
use MarketingOS\Identity\Persistence\PdoCanonicalContactImporter;
use MarketingOS\Identity\Bridge\PdoMauticContactSnapshotReader;
use MarketingOS\Identity\Bridge\PdoMauticContactBridge;

$db=mos_test_connection();
(new PdoSchemaMigrator($db))->migrate();
$key=getenv('MOS_SNAPSHOT_HMAC_KEY');
if(!is_string($key)||strlen($key)<32)throw new RuntimeException('C4-08 HMAC key missing');
$a=WorkspaceId::generate();$b=WorkspaceId::generate();
$store=new PdoPersonStore($db);
$store->createWorkspace($a);$store->createWorkspace($b);
// Source OBSERVER must use a separate SQL connection, never see uncommitted
// source changes from the transaction used to create fixture Contacts.
$observer=mos_test_connection();
$reader=new PdoMauticContactSnapshotReader($observer,$key);
$bridge=new PdoMauticContactBridge($observer,$reader,new PdoCanonicalContactImporter($observer));

$pass=0;$fail=0;$sourceId=null;$eventA=null;$eventB=null;$personA=null;$personB=null;
function test08(string $name,Closure $f):void{
    global $pass,$fail;
    try{$f();echo "PASS $name\n";$pass++;}
    catch(Throwable $e){echo "FAIL $name: ".$e->getMessage()."\n";$fail++;}
}
function yes08(bool $value):void{if(!$value)throw new RuntimeException('Assertion failed');}
function rows08(PDO $db,string $table,string $workspace):int{
    if(!in_array($table,['mos_person','mos_legacy_entity_map','mos_evidence','mos_domain_event','mos_outbox','mos_inbox','mos_identity_projection'],true))throw new RuntimeException('Table not allowlisted');
    $stmt=$db->prepare("SELECT COUNT(*) FROM $table WHERE workspace_id=?");
    $stmt->execute([$workspace]);return (int)$stmt->fetchColumn();
}
function seed08(PDO $db,string $suffix):int{
    $s=$db->prepare("INSERT INTO leads(email,firstname,lastname,date_added,is_published,points)
          VALUES(?, 'RealPersisted', 'C408', UTC_TIMESTAMP(),1,0)");
    $s->execute(['c408-'.bin2hex(random_bytes(6)).'-'.$suffix.'@example.invalid']);
    return (int)$db->lastInsertId();
}
function exec08(array $args,array $envUpdates=[]):array{
    $env=array_merge(getenv(),$envUpdates);
    $p=proc_open(array_merge([PHP_BINARY],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
    if(!is_resource($p))throw new RuntimeException('Cannot start subprocess');
    fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($p),trim($stdout),trim($stderr)];
}
function cli08(WorkspaceId $w,string $mode,int $limit=1):array{
    [$exit,$stdout,$stderr]=exec08([dirname(__DIR__).'/bin/messenger_shadow_worker.php',(string)$w,$mode,(string)$limit],
        ['MOS_QUEUE_ENABLED'=>'1','MOS_QUEUE_MODE'=>'SHADOW']);
    if($exit!==0)throw new RuntimeException('CLI '.$mode.' exit '.$exit.' '.$stderr);
    $json=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);
    if($json['mode']!==$mode||$json['workspace_id']!==(string)$w)throw new RuntimeException('Incorrect CLI result');
    return $json['counters'];
}
function crash08(WorkspaceId $w,string $mode):int{
    [$exit,$stdout,$stderr]=exec08([__DIR__.'/c4_08_crash_worker.php',$mode,(string)$w]);
    if($stdout!==''||$stderr!=='')throw new RuntimeException('Unexpected crash worker output');
    return $exit;
}
function queued08(PDO $db,WorkspaceId $w):int{
    $s=$db->prepare("SELECT COUNT(*) FROM mos_messenger_messages WHERE queue_name=?");
    $s->execute(['mos_identity_'.(string)$w]);return (int)$s->fetchColumn();
}
test08('installed Mautic 7.2.1 contains genuine leads table and five MOS migrations',function()use($db){
    $n=(int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='leads'")->fetchColumn();
    $versions=(int)$db->query("SELECT COUNT(*) FROM mos_schema_migration")->fetchColumn();
    yes08($n===1 && $versions===5);
});
test08('rolled-back Mautic source Contact is invisible to independent bridge',function()use($db,$bridge,$a,$observer){
    $db->beginTransaction();$id=seed08($db,'rollback');
    try{
        $blocked=false;
        try{$bridge->importPersisted($a,$id);}
        catch(RuntimeException $e){$blocked=$e->getMessage()==='CONTACT_NOT_FOUND_OR_NOT_PERSISTED';}
        yes08($blocked && rows08($db,'mos_person',(string)$a)===0);
    }finally{$db->rollBack();}
    $s=$observer->prepare('SELECT COUNT(*) FROM leads WHERE id=?');$s->execute([$id]);
    yes08((int)$s->fetchColumn()===0);
});
test08('persisted Mautic Contact and HMAC evidence create full atomic MOS source records',function()use($db,$reader,$bridge,$a,&$sourceId,&$eventA,&$personA){
    $sourceId=seed08($db,'main');
    $fingerprint=$reader->fingerprint($a,$sourceId);
    yes08(strlen($fingerprint)===64);
    $import=$bridge->importPersisted($a,$sourceId);
    yes08($import->created&&$import->eventId!==null);
    $eventA=$import->eventId;$personA=(string)$import->mapping->personId;
    foreach(['mos_person','mos_legacy_entity_map','mos_evidence','mos_domain_event','mos_outbox'] as $table){
        yes08(rows08($db,$table,(string)$a)===1);
    }
    $stmt=$db->prepare("SELECT e.content_sha256,d.payload_json FROM mos_evidence e JOIN mos_domain_event d
       ON e.workspace_id=d.workspace_id AND e.id=d.evidence_id WHERE d.workspace_id=? AND d.event_id=?");
    $stmt->execute([(string)$a,(string)$eventA]);$r=$stmt->fetch(PDO::FETCH_ASSOC);
    yes08($r!==false&&hash_equals($fingerprint,$r['content_sha256']));
    yes08(!str_contains($r['payload_json'],'@')&&!str_contains($r['payload_json'],'RealPersisted'));
});
test08('same Mautic Contact in second Workspace creates independent Person and evidence',function()use($db,$bridge,$b,&$sourceId,&$eventB,&$personA,&$personB){
    $import=$bridge->importPersisted($b,$sourceId);
    $eventB=$import->eventId;$personB=(string)$import->mapping->personId;
    yes08($import->created&&$eventB!==null&&$personB!==$personA);
    yes08(rows08($db,'mos_person',(string)$b)===1 && rows08($db,'mos_evidence',(string)$b)===1);
});
test08('reimport is idempotent and leaves Event and Outbox unchanged',function()use($db,$bridge,$a,$b,&$sourceId){
    foreach([$a,$b] as $w){
        $original=rows08($db,'mos_domain_event',(string)$w);
        $again=$bridge->importPersisted($w,$sourceId);
        yes08(!$again->created&&rows08($db,'mos_domain_event',(string)$w)===$original);
    }
});
test08('before publication, durable Outbox survives absence of publisher process',function()use($db,$a,$b){
    yes08(queued08($db,$a)===0 && queued08($db,$b)===0);
    yes08(rows08($db,'mos_outbox',(string)$a)===1 && rows08($db,'mos_outbox',(string)$b)===1);
});
test08('separate bounded PUBLISH process emits only Workspace A pointer',function()use($db,$a,$b,&$eventA){
    $count=cli08($a,'PUBLISH');
    yes08($count['published']===1 && queued08($db,$a)===1 && queued08($db,$b)===0);
    $s=$db->prepare("SELECT body FROM mos_messenger_messages WHERE queue_name=?");
    $s->execute(['mos_identity_'.(string)$a]);$body=(string)$s->fetchColumn();
    yes08(str_contains($body,(string)$eventA) && !str_contains($body,'@') &&
          !str_contains($body,'RealPersisted') && !str_contains($body,(string)getenv('MOS_SNAPSHOT_HMAC_KEY')));
});
test08('independent CONSUME process commits Inbox/Projection and ACKs queue',function()use($db,$a,$b,&$eventA,&$personA){
    $count=cli08($a,'CONSUME');
    yes08($count['processed']===1 && queued08($db,$a)===0);
    yes08(rows08($db,'mos_inbox',(string)$a)===1 &&
          rows08($db,'mos_identity_projection',(string)$a)===1 &&
          rows08($db,'mos_inbox',(string)$b)===0);
    $s=$db->prepare("SELECT imported_event_id FROM mos_identity_projection WHERE workspace_id=? AND person_id=?");
    $s->execute([(string)$a,$personA]);yes08($s->fetchColumn()===(string)$eventA);
});
test08('missing transport ACK after enqueue: real process exits 77, Outbox remains pending',function()use($db,$a,$bridge,&$sourceId){
    $new=seed08($db,'lost-ack');$result=$bridge->importPersisted($a,$new);
    yes08($result->created && crash08($a,'send_then_die')===77);
    yes08(queued08($db,$a)===1);
    $s=$db->prepare("SELECT published_at FROM mos_outbox WHERE workspace_id=? AND event_id=?");
    $s->execute([(string)$a,(string)$result->eventId]);yes08($s->fetchColumn()===null);
});
test08('publisher replay creates duplicate pointers but only one Inbox projection',function()use($db,$a){
    $pub=cli08($a,'PUBLISH');yes08($pub['published']===1 && queued08($db,$a)===2);
    $consume=cli08($a,'CONSUME',2);
    yes08($consume['processed']===1 && $consume['replayed']===1 && queued08($db,$a)===0);
    yes08(rows08($db,'mos_identity_projection',(string)$a)===2);
});
test08('crash after Inbox commit before ACK: broker replay preserves exactly one projection',function()use($db,$b,&$personB){
    yes08(cli08($b,'PUBLISH')['published']===1);
    yes08(crash08($b,'receive_then_die')===77);
    yes08(rows08($db,'mos_inbox',(string)$b)===1 &&
          rows08($db,'mos_identity_projection',(string)$b)===1 && queued08($db,$b)===1);
    $s=$db->prepare("UPDATE mos_messenger_messages SET delivered_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 SECOND) WHERE queue_name=?");
    $s->execute(['mos_identity_'.(string)$b]);yes08($s->rowCount()===1);
    yes08(cli08($b,'CONSUME')['replayed']===1 && queued08($db,$b)===0);
    yes08(rows08($db,'mos_identity_projection',(string)$b)===1);
});
test08('source mutation is refused until C5 reconciliation; historical evidence intact',function()use($db,$bridge,$a,&$sourceId){
    $before=rows08($db,'mos_evidence',(string)$a);
    $s=$db->prepare("UPDATE leads SET firstname='ChangedAfterImport' WHERE id=?");$s->execute([$sourceId]);
    $blocked=false;
    try{$bridge->importPersisted($a,$sourceId);}
    catch(RuntimeException $e){$blocked=$e->getMessage()==='CHANGED_CONTACT_NEEDS_C5_RECONCILIATION';}
    yes08($blocked && rows08($db,'mos_evidence',(string)$a)===$before);
});
test08('queues drained, no external sends or raw Contact PII persisted in queue',function()use($db,$a,$b){
    yes08(queued08($db,$a)===0 && queued08($db,$b)===0);
    foreach([$a,$b] as $w)yes08(cli08($w,'HEALTH')['queue_pending']===0);
    $count=(int)$db->query("SELECT COUNT(*) FROM mos_messenger_failure")->fetchColumn();
    yes08($count===0);
});
echo "C4-08 SUMMARY $pass passed, $fail failed\n";
exit($fail===0?0:1);
