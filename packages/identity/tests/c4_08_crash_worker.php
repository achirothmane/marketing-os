<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require dirname(__DIR__,3).'/vendor/autoload.php';
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Contracts\Id\EventId;
use MarketingOS\Identity\Persistence\PdoOutboxPublisher;
use MarketingOS\Identity\Persistence\PdoInboxConsumer;
use MarketingOS\Identity\Transport\MosDoctrineTransportFactory;
use MarketingOS\Identity\Transport\MosEventPointer;
use MarketingOS\Identity\Transport\MosIdentityProjection;
use Symfony\Component\Messenger\Envelope;
try{
    if($argc!==3||!in_array($argv[1],['send_then_die','receive_then_die'],true)){
        throw new RuntimeException('INVALID_TEST_MODE');
    }
    $w=WorkspaceId::fromString($argv[2]);
    $db=mos_test_connection();
    $dsn=(string)getenv('MOS_TEST_DSN');
    if(!preg_match('/^mysql:host=([^;]+);port=(\d+);dbname=([^;]+)/',$dsn,$match)){
        throw new RuntimeException('INVALID_TEST_DSN');
    }
    $params=['driver'=>'pdo_mysql','host'=>$match[1],'port'=>(int)$match[2],
      'dbname'=>$match[3],'user'=>(string)getenv('MOS_TEST_DB_USER'),
      'password'=>(string)getenv('MOS_TEST_DB_PASSWORD'),'charset'=>'utf8mb4'];
    $transport=MosDoctrineTransportFactory::create($w,$params,60);
    if($argv[1]==='send_then_die'){
        (new PdoOutboxPublisher($db))->publishOne(static function(array $ev)use($transport):void{
            $transport->send(new Envelope(new MosEventPointer($ev['workspace_id'],$ev['event_id'])));
            // Real process exit AFTER queue transaction, BEFORE source Outbox ACK.
            exit(77);
        },30,$w);
    } else {
        foreach($transport->get() as $envelope){
            $pointer=$envelope->getMessage();
            if(!$pointer instanceof MosEventPointer||$pointer->workspaceId!==(string)$w)exit(3);
            (new PdoInboxConsumer($db))->consume($w,EventId::fromString($pointer->eventId),
                'mos_identity_projection',MosIdentityProjection::apply(...));
            // Durable Inbox and projection COMMIT precedes lost queue ACK.
            exit(77);
        }
        exit(4);
    }
    exit(5);
}catch(Throwable $e){fwrite(STDERR,'C4-08 injected worker failed (no PII)'.PHP_EOL);exit(6);}
