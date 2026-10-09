<?php
declare(strict_types=1);
require dirname(__DIR__).'/tests/bootstrap.php';
require dirname(__DIR__,3).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Transport\MosDoctrineTransportFactory;
use MarketingOS\Identity\Transport\MosMessengerRelay;
use MarketingOS\Identity\Transport\MosBoundedReceiver;
use MarketingOS\Identity\Transport\MosFailureLedger;
use MarketingOS\Identity\Persistence\PdoOutboxPublisher;
use MarketingOS\Identity\Persistence\PdoInboxConsumer;

/**
 * Explicit SHADOW operator worker. No scheduler, campaign action or email.
 * Workspace is required; no all-tenant defaults.
 */
try {
    if(getenv('MOS_QUEUE_ENABLED')!=='1'||getenv('MOS_QUEUE_MODE')!=='SHADOW') {
        throw new RuntimeException('DISABLED');
    }
    if($argc!==4||!in_array($argv[2],['PUBLISH','CONSUME','HEALTH'],true)||
       !preg_match('/^[1-9][0-9]*$/D',$argv[3]))throw new InvalidArgumentException('INVALID_ARGS');
    $workspace=WorkspaceId::fromString($argv[1]);
    $limit=(int)$argv[3];
    if($limit<1||$limit>100)throw new InvalidArgumentException('LIMIT_EXCEEDED');
    $db=mos_test_connection();
    $raw=(string)getenv('MOS_TEST_DSN');
    if(!preg_match('/^mysql:host=([^;]+);port=([0-9]+);dbname=([^;]+)(?:;.*)?$/D',$raw,$parts)) {
        throw new RuntimeException('INVALID_DSN');
    }
    $params=[
      'driver'=>'pdo_mysql','host'=>$parts[1],'port'=>(int)$parts[2],
      'dbname'=>$parts[3],
      'user'=>(string)getenv('MOS_TEST_DB_USER'),
      'password'=>(string)getenv('MOS_TEST_DB_PASSWORD'),
      'charset'=>'utf8mb4'
    ];
    $q=$db->prepare('SELECT id FROM mos_workspace WHERE id=?');
    $q->execute([(string)$workspace]);
    if($q->fetchColumn()===false)throw new RuntimeException('UNKNOWN_WORKSPACE');
    $queue='mos_identity_'.(string)$workspace;
    $lockName='mos_worker_'.substr(hash('sha256',$queue.'_'.$argv[2]),0,36);
    $lock=$db->prepare('SELECT GET_LOCK(?,0)');
    $lock->execute([$lockName]);
    if((int)$lock->fetchColumn()!==1)throw new RuntimeException('WORKER_LOCKED');
    try{
        $transport=MosDoctrineTransportFactory::create($workspace,$params,60);
        $results=['processed'=>0,'replayed'=>0,'published'=>0,'retried'=>0,'quarantined'=>0,'idle'=>0];
        if($argv[2]==='HEALTH'){
            $outbox=$db->prepare('SELECT COUNT(*) AS pending,MAX(attempts) AS max_attempts,
                TIMESTAMPDIFF(SECOND,MIN(created_at),UTC_TIMESTAMP(6)) AS oldest_seconds
                FROM mos_outbox WHERE workspace_id=? AND published_at IS NULL');
            $outbox->execute([(string)$workspace]);
            $results['outbox']=$outbox->fetch(PDO::FETCH_ASSOC);
            $q=$db->prepare("SELECT COUNT(*) FROM mos_messenger_messages WHERE queue_name=?");
            $q->execute([$queue]);$results['queue_pending']=(int)$q->fetchColumn();
            $q=$db->prepare("SELECT COUNT(*) FROM mos_messenger_failure WHERE workspace_id=? AND quarantined_at IS NOT NULL");
            $q->execute([(string)$workspace]);$results['quarantine_depth']=(int)$q->fetchColumn();
        }elseif($argv[2]==='PUBLISH'){
            $relay=new MosMessengerRelay(new PdoOutboxPublisher($db),$transport);
            for($i=0;$i<$limit;$i++){
                $q=$db->prepare('SELECT COUNT(*) FROM mos_messenger_messages WHERE queue_name=?');
                $q->execute([$queue]);
                if((int)$q->fetchColumn()>=1000)break; // explicit backpressure
                if($relay->publishOne($workspace)===null)break;
                $results['published']++;
            }
        }else{
            $consumer=new MosBoundedReceiver($transport,new PdoInboxConsumer($db),
                new MosFailureLedger($db),$workspace,3);
            for($i=0;$i<$limit;$i++){
                $result=$consumer->runOnce();
                $key=strtolower($result);
                if($result==='PROCESSED')$results['processed']++;
                elseif($result==='REPLAY')$results['replayed']++;
                elseif($result==='QUARANTINED')$results['quarantined']++;
                elseif($result==='RETRY'){$results['retried']++;break;}
                else{$results['idle']++;break;}
            }
        }
        echo json_encode(['workspace_id'=>(string)$workspace,'mode'=>$argv[2],'counters'=>$results],JSON_THROW_ON_ERROR)."\n";
    }finally{
        $release=$db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}catch(Throwable $e){
    // Fail closed. No raw SQL, passwords or Person attributes in stderr.
    fwrite(STDERR,"MOS Messenger SHADOW worker blocked: ".(
        in_array($e->getMessage(),['DISABLED','INVALID_ARGS','LIMIT_EXCEEDED',
        'INVALID_DSN','UNKNOWN_WORKSPACE','WORKER_LOCKED'],true)?$e->getMessage():'WORKER_FAILURE')."\n");
    exit(2);
}
