<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Persistence\PdoSchemaMigrator;
use MarketingOS\Identity\Persistence\PdoPersonStore;

$db=mos_test_connection();
(new PdoSchemaMigrator($db))->migrate();
$store=new PdoPersonStore($db);
$a=WorkspaceId::generate();$b=WorkspaceId::generate();
$c=WorkspaceId::generate();$d=WorkspaceId::generate();$e=WorkspaceId::generate();
foreach([$a,$b,$c,$d,$e] as $w)$store->createWorkspace($w);
$pass=0;$fail=0;$contact=null;$originalEmail=null;

function pb08(string $name,Closure $f):void {
    global $pass,$fail;
    try{$f();echo "PASS $name\n";$pass++;}
    catch(Throwable $e){echo "FAIL $name: ".$e->getMessage()."\n";$fail++;}
}
function require08b(bool $yes):void {if(!$yes)throw new RuntimeException('Assertion failed');}
function run08b(string $file,array $args,array $changes=[]):array {
    $env=array_merge(getenv(),$changes);
    $proc=proc_open(array_merge([PHP_BINARY,$file],$args),
      [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
    if(!is_resource($proc))throw new RuntimeException('Failed to launch PHP subprocess.');
    fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($proc),trim($out),trim($err)];
}
function active08b():array {
    return [
      'MOS_PIPELINE_ENABLED'=>'1',
      'MOS_BRIDGE_MODE'=>'SHADOW',
      'MOS_SCHEDULED_SHADOW_ENABLED'=>'1',
      'MOS_SUPERVISOR_ENABLED'=>'1',
      'MOS_QUEUE_ENABLED'=>'1',
      'MOS_QUEUE_MODE'=>'SHADOW',
      'MOS_DLQ_KEY_B64'=>base64_encode(str_repeat('Q',32))
    ];
}
function pipeline08b(WorkspaceId $w,array $changes=[],
                     array $limits=['100','4','2','20']):array {
    $file=dirname(__DIR__).'/bin/run_shadow_pipeline.php';
    return run08b($file,array_merge([(string)$w],$limits),array_merge(active08b(),$changes));
}
function expectReceipt08b(WorkspaceId $w,array $changes=[]):array {
    [$exit,$out,$err]=pipeline08b($w,$changes);
    if($exit!==0)throw new RuntimeException("Pipeline failed: ".$err);
    $result=json_decode($out,true,32,JSON_THROW_ON_ERROR);
    require08b($result['mode']==='BOUNDED_SHADOW_PIPELINE');
    require08b($result['workspace_id']===(string)$w);
    require08b($result['source']['batches']<=4 && $result['queue']['cycles']===2);
    require08b(!str_contains($out,'@example.invalid'));
    require08b(!str_contains($out,(string)getenv('MOS_SNAPSHOT_HMAC_KEY')));
    return $result;
}
function sourceOnly08b(WorkspaceId $w):array {
    return run08b(dirname(__DIR__).'/bin/run_scheduled_shadow_scan.php',
      [(string)$w],array_merge(active08b(),[
        'MOS_SCAN_BATCH_SIZE'=>'100','MOS_SCAN_MAX_BATCHES'=>'4','MOS_SCAN_MAX_SECONDS'=>'30'
      ]));
}
function worker08b(WorkspaceId $w,string $mode):array {
    return run08b(dirname(__DIR__).'/bin/messenger_shadow_worker.php',
      [(string)$w,$mode,'20'],active08b());
}
function crash08b(WorkspaceId $w,string $mode):int {
    [$code,$out,$err]=run08b(__DIR__.'/c4_08_crash_worker.php',
      [$mode,(string)$w],active08b());
    require08b($out==='' && $err==='');
    return $code;
}
function countTenant08b(PDO $db,string $table,WorkspaceId $w):int {
    if(!in_array($table,['mos_person','mos_evidence','mos_domain_event',
        'mos_outbox','mos_inbox','mos_identity_projection'],true))
        throw new InvalidArgumentException('Unexpected test table');
    $q=$db->prepare("SELECT COUNT(*) FROM $table WHERE workspace_id=?");
    $q->execute([(string)$w]);return (int)$q->fetchColumn();
}
function queued08b(PDO $db,WorkspaceId $w):int {
    $q=$db->prepare('SELECT COUNT(*) FROM mos_messenger_messages WHERE queue_name=?');
    $q->execute(['mos_identity_'.(string)$w]);return (int)$q->fetchColumn();
}
function seedContact08b(PDO $db,string $name):int {
    $email='c408b-'.bin2hex(random_bytes(7)).'@example.invalid';
    $s=$db->prepare("INSERT INTO leads(email,firstname,lastname,date_added,is_published,points)
      VALUES(?,?, 'Recovery',UTC_TIMESTAMP(),1,0)");
    $s->execute([$email,$name]);
    return (int)$db->lastInsertId();
}
pb08('installed Mautic leads table and schema 006 are present',function()use($db){
    $n=(int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='leads'")->fetchColumn();
    $v=(int)$db->query("SELECT COUNT(*) FROM mos_schema_migration")->fetchColumn();
    require08b($n===1 && $v===6);
});
pb08('default disabled pipeline refuses without importing',function()use($a,$db){
    [$code,$out,$err]=pipeline08b($a,['MOS_PIPELINE_ENABLED'=>'0']);
    require08b($code===2 && $out==='' && str_contains($err,'DISABLED'));
    require08b(countTenant08b($db,'mos_person',$a)===0);
});
pb08('invalid caps and missing DLQ secret are fail-closed',function()use($a){
    [$code,, $err]=pipeline08b($a,[],['100','4','4','20']);
    require08b($code===2 && str_contains($err,'LIMIT_EXCEEDED'));
    [$code,, $err]=pipeline08b($a,['MOS_DLQ_KEY_B64'=>'invalid']);
    require08b($code===2 && str_contains($err,'DLQ_KEY_MISSING'));
});
pb08('uncommitted source Contact remains invisible to independently invoked pipeline',function()use($db,$a){
    $db->beginTransaction();
    try{
        $id=seedContact08b($db,'Uncommitted');
        $receipt=expectReceipt08b($a);
        require08b($receipt['source']['created']===0);
        require08b($receipt['queue']['published']===0);
        require08b(countTenant08b($db,'mos_person',$a)===0);
    }finally{$db->rollBack();}
});
pb08('first automatic bounded cycle imports a real committed Contact through Inbox',function()use($db,$a,&$contact){
    $contact=seedContact08b($db,'CommittedContact');
    $receipt=expectReceipt08b($a);
    require08b($receipt['source']['created']===1 && $receipt['source']['blocked']===0);
    require08b($receipt['queue']['published']===1 && $receipt['queue']['processed']===1);
    require08b(countTenant08b($db,'mos_person',$a)===1);
    require08b(countTenant08b($db,'mos_evidence',$a)===1);
    require08b(countTenant08b($db,'mos_domain_event',$a)===1);
    require08b(countTenant08b($db,'mos_identity_projection',$a)===1);
    require08b(queued08b($db,$a)===0);
});
pb08('repeated bounded pipeline scan and queue stages do not duplicate Person or Event',function()use($db,$a){
    $receipt=expectReceipt08b($a);
    require08b($receipt['source']['created']===0 && $receipt['source']['reused']>=1);
    require08b($receipt['queue']['published']===0);
    require08b(countTenant08b($db,'mos_person',$a)===1 &&
        countTenant08b($db,'mos_domain_event',$a)===1);
});
pb08('second Workspace creates independent identity and quarantines malformed wire before processing',function()use($db,$a,$b){
    $raw='invalid-message c408b-secret@example.invalid';
    $q=$db->prepare("INSERT INTO mos_messenger_messages
      (body,headers,queue_name,created_at,available_at,delivered_at)
      VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),NULL)");
    $q->execute([$raw,'{}','mos_identity_'.(string)$b]);
    $receipt=expectReceipt08b($b);
    require08b($receipt['source']['created']===1);
    require08b($receipt['queue']['wire_quarantined']===1);
    require08b($receipt['queue']['processed']===1);
    $q=$db->prepare("SELECT sealed_payload FROM mos_messenger_wire_quarantine WHERE workspace_id=?");
    $q->execute([(string)$b]);$sealed=$q->fetchColumn();
    require08b(is_string($sealed) && !str_contains($sealed,'c408b-secret@example.invalid'));
    require08b(countTenant08b($db,'mos_identity_projection',$b)===1);
    $pa=$db->prepare("SELECT person_id FROM mos_legacy_entity_map WHERE workspace_id=? LIMIT 1");
    $pa->execute([(string)$a]);$aId=$pa->fetchColumn();
    $pa->execute([(string)$b]);$bId=$pa->fetchColumn();
    require08b($aId!==$bId);
});
pb08('kill scanner after durable Person+Outbox commit before cursor: restart safely recovers',function()use($db,$c){
    [$code,$out,$err]=run08b(__DIR__.'/c4_05c_crash_worker.php',
      [(string)$c],active08b());
    require08b($code===77 && $out==='' && $err==='');
    $cur=$db->prepare('SELECT last_seen_id FROM mos_contact_scan_cursor WHERE workspace_id=?');
    $cur->execute([(string)$c]);require08b((int)$cur->fetchColumn()===0);
    $before=countTenant08b($db,'mos_person',$c);
    require08b($before===1 && countTenant08b($db,'mos_outbox',$c)===1);
    $r=expectReceipt08b($c);
    require08b($r['source']['created']===0 && $r['source']['reused']>=1);
    require08b($r['queue']['processed']===1);
    require08b(countTenant08b($db,'mos_person',$c)===$before);
    require08b(countTenant08b($db,'mos_identity_projection',$c)===1);
});
pb08('process death after Messenger commit before outbox ACK yields one eventual projection',function()use($db,$d){
    [$status,$receipt,$err]=sourceOnly08b($d);
    require08b($status===0 && countTenant08b($db,'mos_outbox',$d)===1);
    require08b(crash08b($d,'send_then_die')===77);
    require08b(queued08b($db,$d)===1);
    $q=$db->prepare("UPDATE mos_outbox SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 20 SECOND)
       WHERE workspace_id=? AND published_at IS NULL");
    $q->execute([(string)$d]);require08b($q->rowCount()===1);
    $r=expectReceipt08b($d);
    require08b($r['source']['created']===0);
    require08b($r['queue']['replayed']===1 && $r['queue']['processed']===1);
    require08b(countTenant08b($db,'mos_identity_projection',$d)===1 && queued08b($db,$d)===0);
});
pb08('process death after Inbox commit before Messenger ACK replays without duplicate projection',function()use($db,$e){
    [$status]=$source=sourceOnly08b($e);
    require08b($status===0 && countTenant08b($db,'mos_outbox',$e)===1);
    [$status,$out,$err]=worker08b($e,'PUBLISH');
    require08b($status===0 && queued08b($db,$e)===1);
    require08b(crash08b($e,'receive_then_die')===77);
    require08b(countTenant08b($db,'mos_identity_projection',$e)===1 && queued08b($db,$e)===1);
    $q=$db->prepare("UPDATE mos_messenger_messages
       SET delivered_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 120 SECOND) WHERE queue_name=?");
    $q->execute(['mos_identity_'.(string)$e]);
    $r=expectReceipt08b($e);
    require08b($r['queue']['replayed']===1 && $r['queue']['processed']===0);
    require08b(countTenant08b($db,'mos_identity_projection',$e)===1 && queued08b($db,$e)===0);
});
pb08('source changed without authority stops queue phase and leaves durable Outbox pending',function()use($db,$a,&$contact){
    $q=$db->prepare("UPDATE leads SET firstname='ChangedWithoutAuthority' WHERE id=?");
    $q->execute([$contact]);
    seedContact08b($db,'NewAfterConflict');
    [$code,$out,$err]=pipeline08b($a);
    require08b($code===2 && $out==='' && str_contains($err,'SOURCE_SCAN_FAILED'));
    require08b(countTenant08b($db,'mos_person',$a)===2);
    require08b(countTenant08b($db,'mos_identity_projection',$a)===1);
    require08b(queued08b($db,$a)===0);
    $s=$db->prepare("SELECT COUNT(*) FROM mos_outbox WHERE workspace_id=? AND published_at IS NULL");
    $s->execute([(string)$a]);require08b((int)$s->fetchColumn()===1);
});
pb08('restoring original source observation enables queued event recovery without new identity',function()use($db,$a,&$contact){
    $q=$db->prepare("UPDATE leads SET firstname='CommittedContact' WHERE id=?");$q->execute([$contact]);
    $r=expectReceipt08b($a);
    require08b($r['source']['blocked']===0 && $r['queue']['processed']===1);
    require08b(countTenant08b($db,'mos_person',$a)===2);
    require08b(countTenant08b($db,'mos_identity_projection',$a)===2);
});
pb08('workspace pipeline advisory lock refuses a second coordinator',function()use($a){
    $other=mos_test_connection();
    $lock='mos_pipeline_'.substr(hash('sha256',(string)$a),0,37);
    $q=$other->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);
    require08b((int)$q->fetchColumn()===1);
    try{
        [$status,$out,$err]=pipeline08b($a);
        require08b($status===2 && str_contains($err,'PIPELINE_BUSY'));
    }finally{$other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
});
echo "C4-08B SUMMARY $pass passed, $fail failed\n";
exit($fail===0?0:1);
