<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require dirname(__DIR__,3).'/vendor/autoload.php';

use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Contracts\Id\EventId;
use MarketingOS\Identity\Persistence\PdoPersonStore;
use MarketingOS\Identity\Persistence\PdoSchemaMigrator;
use MarketingOS\Identity\Persistence\PdoCanonicalContactImporter;
use MarketingOS\Identity\Transport\MosEncryptedWireQuarantine;
use MarketingOS\Identity\Transport\MosMessengerJsonSerializer;
use MarketingOS\Identity\Transport\MosDoctrineTransportFactory;
use MarketingOS\Identity\Transport\MosEventPointer;
use Symfony\Component\Messenger\Envelope;

$db=mos_test_connection();
(new PdoSchemaMigrator($db))->migrate();
$a=WorkspaceId::generate();$b=WorkspaceId::generate();
$people=new PdoPersonStore($db);
$people->createWorkspace($a);$people->createWorkspace($b);
$key=str_repeat('K',32);
$codec=new MosMessengerJsonSerializer();
$scanner=new MosEncryptedWireQuarantine($db,$codec,$key,'ci-test-v1');
$qa='mos_identity_'.(string)$a;
$qb='mos_identity_'.(string)$b;
$dsn=(string)getenv('MOS_TEST_DSN');
if(!preg_match('/^mysql:host=([^;]+);port=([0-9]+);dbname=([^;]+)/D',$dsn,$match))throw new RuntimeException('Missing MySQL test DSN');
$params=['driver'=>'pdo_mysql','host'=>$match[1],'port'=>(int)$match[2],
 'dbname'=>$match[3],'user'=>(string)getenv('MOS_TEST_DB_USER'),
 'password'=>(string)getenv('MOS_TEST_DB_PASSWORD'),'charset'=>'utf8mb4'];
$queueA=MosDoctrineTransportFactory::create($a,$params,60);
$pass=0;$fail=0;$validId=null;$badId=null;
function checkWire(string $description,Closure $test):void{
    global $pass,$fail;
    try{$test();echo "PASS $description\n";$pass++;}
    catch(Throwable $e){echo "FAIL $description: ".$e->getMessage()."\n";$fail++;}
}
function okWire(bool $condition):void{if(!$condition)throw new RuntimeException('Assertion failed');}
function insertWire(PDO $db,string $queue,string $body,string $headers,?string $delivered=null):int{
    $s=$db->prepare("INSERT INTO mos_messenger_messages
       (body,headers,queue_name,created_at,available_at,delivered_at)
       VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),".($delivered??"NULL").")");
    $s->execute([$body,$headers,$queue]);
    return (int)$db->lastInsertId();
}
function wireRow(PDO $db,int $id):?array{
    $q=$db->prepare("SELECT * FROM mos_messenger_messages WHERE id=?");
    $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    return $row===false?null:$row;
}
function archivedWire(PDO $db,int $id):?array{
    $q=$db->prepare("SELECT * FROM mos_messenger_wire_quarantine WHERE source_message_id=?");
    $q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
    return $r===false?null:$r;
}
function wireProcess(array $args,array $extra=[]):array{
    $env=array_merge(getenv(),$extra);
    $p=proc_open(array_merge([PHP_BINARY],$args),
      [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
    if(!is_resource($p))throw new RuntimeException('Process could not start');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($p),trim($out),trim($err)];
}
function wireEnv():array{
    return ['MOS_QUEUE_ENABLED'=>'1','MOS_QUEUE_MODE'=>'SHADOW',
      'MOS_DLQ_KEY_B64'=>base64_encode(str_repeat('K',32))];
}
checkWire('migration 006 creates checksum-pinned encrypted quarantine table',function()use($db){
    $count=(int)$db->query('SELECT COUNT(*) FROM mos_schema_migration')->fetchColumn();
    okWire($count===6);
    $s=$db->query("SHOW COLUMNS FROM mos_messenger_wire_quarantine LIKE 'sealed_payload'");
    okWire($s->fetch(PDO::FETCH_ASSOC)!==false);
});
checkWire('valid pointer remains byte-identical and undelivered during safe wire scan',function()use($queueA,$scanner,$db,$a,&$validId,$qa){
    $msg=new MosEventPointer((string)$a,(string)EventId::generate());
    $queueA->send(new Envelope($msg));
    $s=$db->prepare("SELECT id FROM mos_messenger_messages WHERE queue_name=? ORDER BY id DESC LIMIT 1");
    $s->execute([$qa]);$validId=(int)$s->fetchColumn();
    $before=wireRow($db,$validId);
    $result=$scanner->scan($a,10);
    $after=wireRow($db,$validId);
    okWire($result['valid']===1 && $result['quarantined']===0);
    okWire($after!==null && $before['body']===$after['body'] && $before['headers']===$after['headers'] && $after['delivered_at']===null);
});
checkWire('invalid wire moves atomically to encrypted DLQ without plaintext PII',function()use($db,$scanner,$a,$qa,&$badId,$key){
    $headers=json_encode(['type'=>'mos.event_pointer.v1'],JSON_THROW_ON_ERROR);
    $body="{bad-json owner-email=alice@example.invalid";
    $badId=insertWire($db,$qa,$body,$headers,'NULL');
    $r=$scanner->scan($a,25);
    $archived=archivedWire($db,$badId);
    okWire($r['quarantined']===1 && wireRow($db,$badId)===null && $archived!==null);
    okWire($archived['reason_code']==='INVALID_WIRE_ENVELOPE' && $archived['key_id']==='ci-test-v1');
    okWire(!str_contains($archived['sealed_payload'],'alice@example.invalid') && !str_contains($archived['sealed_payload'],$body));
    $aad=(string)$a.'|'.$qa.'|'.$badId;
    $raw=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
      $archived['sealed_payload'],$aad,$archived['sealed_nonce'],$key);
    okWire(is_string($raw));
    $headerLength=unpack('N',substr($raw,0,4))[1];
    okWire(substr($raw,4,$headerLength)===$headers && substr($raw,4+$headerLength)===$body);
    okWire(hash_equals($archived['payload_sha256'],hash('sha256',$raw)));
    $tampered=$archived['sealed_payload'];$tampered[0]=chr(ord($tampered[0])^1);
    okWire(sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
      $tampered,$aad,$archived['sealed_nonce'],$key)===false);
});
checkWire('non-JSON header bytes are encrypted and quarantined',function()use($db,$scanner,$a,$qa){
    $id=insertWire($db,$qa,'{}','{{not-json');
    $r=$scanner->scan($a,20);
    okWire($r['quarantined']===1 && wireRow($db,$id)===null && archivedWire($db,$id)!==null);
});
checkWire('valid pointer with wrong Workspace in a different queue is quarantined',function()use($db,$scanner,$codec,$a,$b,$qb){
    $msg=$codec->encode(new Envelope(new MosEventPointer((string)$a,(string)EventId::generate())));
    $id=insertWire($db,$qb,$msg['body'],json_encode($msg['headers'],JSON_THROW_ON_ERROR));
    $r=$scanner->scan($b,20);
    okWire($r['quarantined']===1 && archivedWire($db,$id)['reason_code']==='WRONG_WORKSPACE');
});
checkWire('oversized malformed wire is retained for review, never deleted',function()use($db,$scanner,$a,$qa){
    $id=insertWire($db,$qa,str_repeat('x',262145),'{}');
    $r=$scanner->scan($a,20);
    okWire($r['oversized']===1 && wireRow($db,$id)!==null && archivedWire($db,$id)===null);
    $q=$db->prepare('DELETE FROM mos_messenger_messages WHERE id=?');$q->execute([$id]); // test-only cleanup
});
checkWire('freshly claimed message is not stolen, expired malformed message is archived',function()use($db,$scanner,$a,$qa){
    $id=insertWire($db,$qa,'broken','{}','UTC_TIMESTAMP()');
    $r=$scanner->scan($a,30);
    okWire(wireRow($db,$id)!==null && $r['quarantined']===0);
    $s=$db->prepare("UPDATE mos_messenger_messages SET delivered_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 SECOND) WHERE id=?");
    $s->execute([$id]);
    $r=$scanner->scan($a,30);
    okWire($r['quarantined']===1 && wireRow($db,$id)===null);
});
checkWire('duplicate archival identity rolls back deletion and preserves source',function()use($db,$scanner,$a,$qa,&$badId){
    $id=$badId; // test-only reinsert source ID already in encrypted DLQ
    $s=$db->prepare("INSERT INTO mos_messenger_messages
      (id,body,headers,queue_name,created_at,available_at)
      VALUES(?,?,?, ?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $s->execute([$id,'badagain','{}',$qa]);
    $blocked=false;
    try{$scanner->scan($a,30);}catch(PDOException $e){$blocked=true;}
    okWire($blocked && wireRow($db,$id)!==null && archivedWire($db,$id)!==null);
    $db->prepare('DELETE FROM mos_messenger_messages WHERE id=?')->execute([$id]); // test-only cleanup
});
checkWire('manual WIRE_SCAN refuses missing secret, then reports PII-free archival count',function()use($db,$a,$qa){
    $id=insertWire($db,$qa,'broken bob@example.invalid','{}');
    $cli=dirname(__DIR__).'/bin/messenger_shadow_worker.php';
    [$status,$out,$err]=wireProcess([$cli,(string)$a,'WIRE_SCAN','20'],
       ['MOS_QUEUE_ENABLED'=>'1','MOS_QUEUE_MODE'=>'SHADOW','MOS_DLQ_KEY_B64'=>'bad']);
    okWire($status===2 && str_contains($err,'DLQ_KEY_MISSING') && wireRow($db,$id)!==null);
    [$status,$out,$err]=wireProcess([$cli,(string)$a,'WIRE_SCAN','20'],wireEnv());
    okWire($status===0);
    $json=json_decode($out,true,32,JSON_THROW_ON_ERROR);
    okWire($json['counters']['wire_scan']['quarantined']===1 && archivedWire($db,$id)!==null);
    okWire(!str_contains($out,'bob@example.invalid') && !str_contains($err,'bob@example.invalid'));
});
checkWire('supervisor refuses default-off and invalid cycle count',function()use($a){
    $cli=dirname(__DIR__).'/bin/messenger_shadow_supervisor.php';
    [$s,$out,$err]=wireProcess([$cli,(string)$a,'1','10'],wireEnv()+['MOS_SUPERVISOR_ENABLED'=>'0']);
    okWire($s===2 && str_contains($err,'DISABLED'));
    [$s,$out,$err]=wireProcess([$cli,(string)$a,'11','10'],array_merge(wireEnv(),['MOS_SUPERVISOR_ENABLED'=>'1']));
    okWire($s===2 && str_contains($err,'LIMIT_EXCEEDED'));
});
checkWire('bounded supervisor archives malformed wire then publishes/consumes real domain event',function()use($db,$a,$qa){
    // Remove initial valid fake pointer before consuming an unknown test event.
    $q=$db->prepare("DELETE FROM mos_messenger_messages WHERE queue_name=?");
    $q->execute([$qa]);
    $id=insertWire($db,$qa,'not-json secret=carol@example.invalid','{}');
    $import=(new PdoCanonicalContactImporter($db))->import($a,777123,hash('sha256','c4-07b2-supervisor-fixture'));
    okWire($import->created && $import->eventId!==null);
    $cli=dirname(__DIR__).'/bin/messenger_shadow_supervisor.php';
    [$s,$out,$err]=wireProcess([$cli,(string)$a,'2','10'],
      array_merge(wireEnv(),['MOS_SUPERVISOR_ENABLED'=>'1']));
    okWire($s===0);
    $r=json_decode($out,true,32,JSON_THROW_ON_ERROR);
    okWire($r['counters']['wire_quarantined']===1 &&
      $r['counters']['published']===1 && $r['counters']['processed']===1 &&
      $r['counters']['cycles']===2);
    $s=$db->prepare('SELECT COUNT(*) FROM mos_identity_projection WHERE workspace_id=? AND imported_event_id=?');
    $s->execute([(string)$a,(string)$import->eventId]);
    okWire((int)$s->fetchColumn()===1);
    okWire(archivedWire($db,$id)!==null && wireRow($db,$id)===null);
    okWire(!str_contains($out,'carol@example.invalid') && !str_contains($err,'carol@example.invalid'));
});
checkWire('oversized poison halts supervisor before publishing and preserves source',function()use($db,$a,$qa){
    $id=insertWire($db,$qa,str_repeat('x',262145),'{}');
    $imp=(new PdoCanonicalContactImporter($db))->import($a,777124,hash('sha256','c4-07b2-oversized-fixture'));
    $cli=dirname(__DIR__).'/bin/messenger_shadow_supervisor.php';
    [$status,$out,$err]=wireProcess([$cli,(string)$a,'1','10'],
      array_merge(wireEnv(),['MOS_SUPERVISOR_ENABLED'=>'1']));
    okWire($status===2 && str_contains($err,'WIRE_REQUIRES_MANUAL_REVIEW') &&
      wireRow($db,$id)!==null);
    $s=$db->prepare('SELECT published_at FROM mos_outbox WHERE workspace_id=? AND event_id=?');
    $s->execute([(string)$a,(string)$imp->eventId]);
    okWire($s->fetchColumn()===null);
    $db->prepare('DELETE FROM mos_messenger_messages WHERE id=?')->execute([$id]); // cleanup fixture
});
echo "C4-07B2 SUMMARY $pass passed, $fail failed\n";
exit($fail===0?0:1);
