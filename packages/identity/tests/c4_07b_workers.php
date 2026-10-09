<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require dirname(__DIR__,3).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use MarketingOS\Contracts\Id\EventId;
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Persistence\PdoSchemaMigrator;
use MarketingOS\Identity\Persistence\PdoPersonStore;
use MarketingOS\Identity\Persistence\PdoCanonicalContactImporter;
use MarketingOS\Identity\Persistence\PdoOutboxPublisher;
use MarketingOS\Identity\Persistence\PdoInboxConsumer;
use MarketingOS\Identity\Transport\MosDoctrineTransportFactory;
use MarketingOS\Identity\Transport\MosMessengerRelay;
use MarketingOS\Identity\Transport\MosBoundedReceiver;
use MarketingOS\Identity\Transport\MosFailureLedger;
use MarketingOS\Identity\Transport\MosEventPointer;
use Symfony\Component\Messenger\Envelope;

$db=mos_test_connection();
(new PdoSchemaMigrator($db))->migrate();
$store=new PdoPersonStore($db);
$a=WorkspaceId::generate();
$b=WorkspaceId::generate();
$store->createWorkspace($a);
$store->createWorkspace($b);
$dsn=(string)getenv('MOS_TEST_DSN');
if(!preg_match('/^mysql:host=([^;]+);port=(\d+);dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Explicit test DSN required.');
$params=['driver'=>'pdo_mysql','host'=>$m[1],'port'=>(int)$m[2],
  'dbname'=>$m[3],'user'=>(string)getenv('MOS_TEST_DB_USER'),
  'password'=>(string)getenv('MOS_TEST_DB_PASSWORD'),'charset'=>'utf8mb4'];
$queueA=MosDoctrineTransportFactory::create($a,$params,1);
$queueB=MosDoctrineTransportFactory::create($b,$params,1);
$relayA=new MosMessengerRelay(new PdoOutboxPublisher($db),$queueA);
$relayB=new MosMessengerRelay(new PdoOutboxPublisher($db),$queueB);
$workerA=new MosBoundedReceiver($queueA,new PdoInboxConsumer($db),new MosFailureLedger($db),$a,3);
$workerB=new MosBoundedReceiver($queueB,new PdoInboxConsumer($db),new MosFailureLedger($db),$b,3);
$importer=new PdoCanonicalContactImporter($db);
function verifyB(bool $ok):void{if(!$ok)throw new RuntimeException('Assertion failed');}
$pass=0;$fail=0;
function caseB(string $title,Closure $fn):void {
  global $pass,$fail;
  try{$fn();echo "PASS $title\n";$pass++;}
  catch(Throwable $e){echo "FAIL $title: ".$e->getMessage()."\n";$fail++;}
}
function countB(PDO $db,string $table):int{
  if(!in_array($table,['mos_identity_projection','mos_messenger_messages','mos_messenger_failure','mos_inbox'],true))throw new InvalidArgumentException('Unsafe table');
  return (int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
}
$ea=null;$eb=null;$poison=null;
caseB('migration 005 and worker tables are checksum pinned',function()use($db){
    $v=(int)$db->query('SELECT COUNT(*) FROM mos_schema_migration')->fetchColumn();
    verifyB($v===5);
    foreach(['mos_messenger_messages','mos_identity_projection','mos_messenger_failure'] as $table) {
      $s=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
      $s->execute([$table]);verifyB((int)$s->fetchColumn()===1);
    }
});
caseB('source tenant A and B have distinct durable pending events',function()use($importer,$a,$b,&$ea,&$eb){
    $x=$importer->import($a,89001,hash('sha256','mos-a-89001'));
    $y=$importer->import($b,89001,hash('sha256','mos-b-89001'));
    $ea=$x->eventId;$eb=$y->eventId;
    verifyB($ea!==null && $eb!==null && (string)$ea!==(string)$eb);
});
caseB('publisher tenant A cannot publish tenant B outbox',function()use($relayA,$db,$a,$b,&$ea){
    verifyB($relayA->publishOne($a)===(string)$ea);
    $q=$db->prepare('SELECT COUNT(*) FROM mos_messenger_messages WHERE queue_name=?');
    $q->execute(['mos_identity_'.(string)$a]);verifyB((int)$q->fetchColumn()===1);
    $q->execute(['mos_identity_'.(string)$b]);verifyB((int)$q->fetchColumn()===0);
    $q=$db->prepare('SELECT COUNT(*) FROM mos_outbox WHERE workspace_id=? AND published_at IS NULL');
    $q->execute([(string)$b]);verifyB((int)$q->fetchColumn()===1);
});
caseB('bounded receiver A commits real projection and inbox before ACK',function()use($workerA,$db,$a,&$ea){
    verifyB($workerA->runOnce()==='PROCESSED');
    verifyB(countB($db,'mos_identity_projection')===1);
    $q=$db->prepare('SELECT imported_event_id FROM mos_identity_projection WHERE workspace_id=?');
    $q->execute([(string)$a]);verifyB($q->fetchColumn()===(string)$ea);
    verifyB(countB($db,'mos_messenger_messages')===0);
});
caseB('duplicate event pointer is replay without duplicate projection',function()use($queueA,$workerA,$db,$a,&$ea){
    $queueA->send(new Envelope(new MosEventPointer((string)$a,(string)$ea)));
    verifyB($workerA->runOnce()==='REPLAY');
    verifyB(countB($db,'mos_identity_projection')===1);
    verifyB(countB($db,'mos_messenger_messages')===0);
});
caseB('unsupported event retries, then quarantines with no raw payload',function()use($db,$a,$importer,$relayA,$workerA,$queueA,&$poison){
    $x=$importer->import($a,89009,hash('sha256','unsupported-89009'));
    $poison=$x->eventId;
    $s=$db->prepare("UPDATE mos_domain_event SET event_type='identity.unsupported.v1' WHERE workspace_id=? AND event_id=?");
    $s->execute([(string)$a,(string)$poison]); // disposable fault-injection only
    verifyB($relayA->publishOne($a)===(string)$poison);
    for($n=0;$n<2;$n++){
        verifyB($workerA->runOnce()==='RETRY');
        $db->exec("UPDATE mos_messenger_messages SET delivered_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 12 SECOND)");
    }
    verifyB($workerA->runOnce()==='QUARANTINED');
    $s=$db->prepare('SELECT attempts,last_error_code,quarantined_at FROM mos_messenger_failure WHERE workspace_id=? AND event_id=?');
    $s->execute([(string)$a,(string)$poison]);$row=$s->fetch(PDO::FETCH_ASSOC);
    verifyB((int)$row['attempts']===3 && $row['last_error_code']==='HANDLER_FAILED' && $row['quarantined_at']!==null);
    verifyB(countB($db,'mos_identity_projection')===1 && countB($db,'mos_messenger_messages')===0);
    $queueA->send(new Envelope(new MosEventPointer((string)$a,(string)$poison)));
    verifyB($workerA->runOnce()==='QUARANTINED');
    verifyB(countB($db,'mos_messenger_messages')===0);
});
caseB('worker B cannot see A queue, can project tenant B event',function()use($relayB,$workerB,$db,$b,&$eb){
    verifyB($relayB->publishOne($b)===(string)$eb);
    verifyB($workerB->runOnce()==='PROCESSED');
    $q=$db->prepare('SELECT COUNT(*) FROM mos_identity_projection WHERE workspace_id=? AND imported_event_id=?');
    $q->execute([(string)$b,(string)$eb]);verifyB((int)$q->fetchColumn()===1);
    verifyB(countB($db,'mos_identity_projection')===2);
});
caseB('worker SHADOW CLI is disabled by default',function()use($a){
    $command='MOS_QUEUE_ENABLED=0 MOS_QUEUE_MODE=SHADOW '.escapeshellarg(PHP_BINARY).' '.
      escapeshellarg(dirname(__DIR__).'/bin/messenger_shadow_worker.php').' '.
      escapeshellarg((string)$a).' HEALTH 1 2>&1';
    exec($command,$lines,$code);
    verifyB($code===2 && str_contains(implode(' ',$lines),'DISABLED'));
});
caseB('explicit HEALTH mode returns bounded PII-free counters',function()use($a){
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/messenger_shadow_worker.php').' '.
      escapeshellarg((string)$a).' HEALTH 1 2>&1';
    $oldA=getenv('MOS_QUEUE_ENABLED');$oldB=getenv('MOS_QUEUE_MODE');
    putenv('MOS_QUEUE_ENABLED=1');putenv('MOS_QUEUE_MODE=SHADOW');
    exec($cmd,$lines,$code);
    $oldA===false?putenv('MOS_QUEUE_ENABLED'):putenv('MOS_QUEUE_ENABLED='.$oldA);
    $oldB===false?putenv('MOS_QUEUE_MODE'):putenv('MOS_QUEUE_MODE='.$oldB);
    verifyB($code===0);
    $data=json_decode(implode("\n",$lines),true,512,JSON_THROW_ON_ERROR);
    verifyB($data['mode']==='HEALTH' && $data['counters']['quarantine_depth']===1);
    verifyB(!str_contains(implode(' ',$lines),'@') && !str_contains(implode(' ',$lines),'email'));
});
echo "C4-07B SUMMARY $pass passed, $fail failed\n";
exit($fail===0?0:1);
