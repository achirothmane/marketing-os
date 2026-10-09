<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require dirname(__DIR__,3).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use MarketingOS\Contracts\Id\EventId;
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Persistence\PdoCanonicalContactImporter;
use MarketingOS\Identity\Persistence\PdoSchemaMigrator;
use MarketingOS\Identity\Persistence\PdoOutboxPublisher;
use MarketingOS\Identity\Persistence\PdoInboxConsumer;
use MarketingOS\Identity\Persistence\PdoPersonStore;
use MarketingOS\Identity\Transport\MosEventPointer;
use MarketingOS\Identity\Transport\MosMessengerJsonSerializer;
use MarketingOS\Identity\Transport\MosMessengerRelay;
use MarketingOS\Identity\Transport\MosMessengerReceiver;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as DoctrineQueueConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;

$db=mos_test_connection();
(new PdoSchemaMigrator($db))->migrate();
$w=WorkspaceId::generate();
(new PdoPersonStore($db))->createWorkspace($w);
$db->exec("CREATE TABLE mos_test_projection (
  workspace_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (workspace_id,event_id)
) ENGINE=InnoDB");
$dsn=getenv('MOS_TEST_DSN');
if(!preg_match('/^mysql:host=([^;]+);port=([0-9]+);dbname=([^;]+)/D',(string)$dsn,$match)){
    throw new RuntimeException('Test requires explicit mysql DSN.');
}
$options=['driver'=>'pdo_mysql','host'=>$match[1],'port'=>(int)$match[2],
 'dbname'=>$match[3],'user'=>getenv('MOS_TEST_DB_USER'),'password'=>getenv('MOS_TEST_DB_PASSWORD'),'charset'=>'utf8mb4'];
function mosTransport(array $options):DoctrineTransport {
    $dbal=DriverManager::getConnection($options);
    $queue=new DoctrineQueueConnection([
        'table_name'=>'mos_messenger_messages',
        'queue_name'=>'mos_identity',
        'redeliver_timeout'=>1,
        'auto_setup'=>true
    ],$dbal);
    return new DoctrineTransport($queue,new MosMessengerJsonSerializer());
}
$sender=mosTransport($options);
$receiver=mosTransport($options); // independent DB connection simulates process restart
$relay=new MosMessengerRelay(new PdoOutboxPublisher($db),$sender);
$handler=new MosMessengerReceiver($receiver,new PdoInboxConsumer($db));
$importer=new PdoCanonicalContactImporter($db);
$apply=static function(array $event,PDO $connection):void {
    $stmt=$connection->prepare("INSERT INTO mos_test_projection (workspace_id,event_id) VALUES(?,?)");
    $stmt->execute([$event['workspace_id'],$event['event_id']]);
};
$pass=0;$fail=0;
function checkC407(string $name,Closure $test):void {
    global $pass,$fail;
    try{$test();echo "PASS $name\n";$pass++;}
    catch(Throwable $e){echo "FAIL $name: ".$e->getMessage()."\n";$fail++;}
}
function assertC407(bool $condition):void{
    if(!$condition)throw new RuntimeException('Assertion failed');
}
function countC407(PDO $db,string $table):int {
    if(!in_array($table,['mos_outbox','mos_inbox','mos_test_projection','mos_messenger_messages'],true))throw new InvalidArgumentException('Unsafe table.');
    return (int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
}
function newEventC407(PdoCanonicalContactImporter $importer,WorkspaceId $w,int $id):EventId {
    $result=$importer->import($w,$id,hash('sha256',"synthetic-fixture-$id"));
    if($result->eventId===null)throw new RuntimeException('Fixture event missing.');
    return $result->eventId;
}
checkC407('codec rejects arbitrary PHP/PII-bearing message types',function(){
    $codec=new MosMessengerJsonSerializer();
    $rejected=false;
    try{$codec->encode(new Envelope(new stdClass()));}catch(InvalidArgumentException $e){$rejected=true;}
    assertC407($rejected);
    $rejected=false;
    try{$codec->decode(['body'=>'{"schema":1,"workspace_id":"invalid","event_id":"invalid","email":"secret"}',
      'headers'=>['type'=>'mos.event_pointer.v1']]);}catch(MessageDecodingFailedException $e){$rejected=true;}
    assertC407($rejected);
});
$eventA=null;
checkC407('committed source event has pending local Outbox row',function()use($importer,$w,$db,&$eventA){
    $eventA=newEventC407($importer,$w,72001);
    assertC407(countC407($db,'mos_outbox')===1);
    $stmt=$db->query("SELECT published_at FROM mos_outbox LIMIT 1");
    assertC407($stmt->fetchColumn()===null);
});
checkC407('real Messenger Doctrine transport persists pointer and local Outbox ACKs',function()use($relay,$db,&$eventA){
    $sent=$relay->publishOne();
    assertC407($sent===(string)$eventA);
    assertC407(countC407($db,'mos_messenger_messages')===1);
    $sql="SELECT body FROM mos_messenger_messages LIMIT 1";
    $body=(string)$db->query($sql)->fetchColumn();
    assertC407(str_contains($body,(string)$eventA));
    assertC407(!str_contains($body,'@') && !str_contains($body,'email'));
    assertC407((int)$db->query("SELECT COUNT(*) FROM mos_outbox WHERE published_at IS NOT NULL")->fetchColumn()===1);
});
checkC407('new process receives, persists projection and ACKs Doctrine queue',function()use($handler,$apply,$db){
    assertC407($handler->consumeOne($apply));
    assertC407(countC407($db,'mos_inbox')===1);
    assertC407(countC407($db,'mos_test_projection')===1);
    assertC407(countC407($db,'mos_messenger_messages')===0);
});
checkC407('duplicate transport envelopes trigger only one projection',function()use($sender,$handler,$apply,$db,$w,&$eventA){
    $pointer=new MosEventPointer((string)$w,(string)$eventA);
    $sender->send(new Envelope($pointer));
    $sender->send(new Envelope($pointer));
    assertC407($handler->consumeOne($apply));
    assertC407($handler->consumeOne($apply));
    assertC407(countC407($db,'mos_inbox')===1 && countC407($db,'mos_test_projection')===1);
    assertC407(countC407($db,'mos_messenger_messages')===0);
});
$eventB=null;
checkC407('simulate lost publisher ACK after durable transport accepts message',function()use($importer,$w,$sender,$db,&$eventB){
    $eventB=newEventC407($importer,$w,72002);
    $lossy=new class($sender) implements SenderInterface {
        public function __construct(private SenderInterface $transport){}
        public function send(Envelope $envelope):Envelope {
            $sent=$this->transport->send($envelope);
            throw new RuntimeException('ACK_LOST_AFTER_TRANSPORT_COMMIT');
        }
    };
    $relay=new MosMessengerRelay(new PdoOutboxPublisher($db),$lossy);
    $caught=false;
    try{$relay->publishOne();}catch(RuntimeException $e){$caught=$e->getMessage()==='ACK_LOST_AFTER_TRANSPORT_COMMIT';}
    assertC407($caught && countC407($db,'mos_messenger_messages')===1);
    $stmt=$db->prepare("SELECT published_at FROM mos_outbox WHERE workspace_id=? AND event_id=?");
    $stmt->execute([(string)$w,(string)$eventB]);
    assertC407($stmt->fetchColumn()===null);
});
checkC407('publisher retry may queue same pointer again, safe by Inbox dedup',function()use($relay,$handler,$apply,$db,&$eventB){
    assertC407($relay->publishOne()===(string)$eventB);
    assertC407(countC407($db,'mos_messenger_messages')===2);
    assertC407($handler->consumeOne($apply));
    assertC407($handler->consumeOne($apply));
    assertC407(countC407($db,'mos_messenger_messages')===0);
    assertC407(countC407($db,'mos_test_projection')===2);
});
$eventC=null;
checkC407('handler failure rolls back Inbox+projection and leaves durable queue message',function()use($importer,$w,$relay,$handler,$db,&$eventC){
    $eventC=newEventC407($importer,$w,72003);
    assertC407($relay->publishOne()===(string)$eventC);
    $before=countC407($db,'mos_inbox');
    $caught=false;
    try {
        $handler->consumeOne(static function(array $event,PDO $connection):void {
            $stmt=$connection->prepare("INSERT INTO mos_test_projection (workspace_id,event_id) VALUES(?,?)");
            $stmt->execute([$event['workspace_id'],$event['event_id']]);
            throw new RuntimeException('projection failed after write');
        });
    }catch(RuntimeException $e){$caught=true;}
    assertC407($caught);
    assertC407(countC407($db,'mos_inbox')===$before);
    assertC407(countC407($db,'mos_test_projection')===2);
    assertC407(countC407($db,'mos_messenger_messages')===1);
});
checkC407('after simulated lease timeout, fresh worker recovers without lost event',function()use($db,$options,$apply){
    // Accelerate broker lease expiry ONLY in test database.
    $db->exec("UPDATE mos_messenger_messages SET delivered_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 SECOND)");
    $fresh=mosTransport($options);
    $consumer=new MosMessengerReceiver($fresh,new PdoInboxConsumer($db));
    assertC407($consumer->consumeOne($apply));
    assertC407(countC407($db,'mos_test_projection')===3);
    assertC407(countC407($db,'mos_messenger_messages')===0);
});
checkC407('queue fully drained, no external effect or email triggered',function()use($relay,$handler,$apply,$db){
    assertC407($relay->publishOne()===null && !$handler->consumeOne($apply));
    assertC407(countC407($db,'mos_outbox')===3 && countC407($db,'mos_inbox')===3);
});
echo "C4-07A SUMMARY $pass passed, $fail failed\n";
exit($fail===0?0:1);
