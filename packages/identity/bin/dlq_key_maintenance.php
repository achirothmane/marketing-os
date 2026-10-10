<?php
declare(strict_types=1);
require dirname(__DIR__).'/tests/bootstrap.php';
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Transport\MosWireQuarantineKeyManager;

/**
 * Operator-only, bounded maintenance. Never prints/decrypts raw wire bytes.
 *
 * INVENTORY <workspace> <limit> (limit reserved for one predictable CLI format)
 * VERIFY    <workspace> <from_key_id> <limit> <after_id>
 * ROTATE    <workspace> <from_key_id> <to_key_id> <limit>
 *
 * Key material must arrive via externally supplied environment secrets.
 */
try {
    if(getenv('MOS_DLQ_MAINTENANCE_ENABLED')!=='1' ||
       getenv('MOS_QUEUE_MODE')!=='SHADOW')throw new RuntimeException('DISABLED');
    if($argc<4)throw new InvalidArgumentException('INVALID_ARGS');
    $workspace=WorkspaceId::fromString($argv[1]);
    $operation=$argv[2];
    if(!in_array($operation,['INVENTORY','VERIFY','ROTATE'],true))throw new InvalidArgumentException('INVALID_ARGS');
    $numeric=static function(string $value,int $min,int $max):int {
        if(!preg_match('/^(0|[1-9][0-9]*)$/D',$value)||
           (int)$value<$min||(int)$value>$max)throw new InvalidArgumentException('LIMIT_EXCEEDED');
        return (int)$value;
    };
    $readKey=static function(string $env):string {
        $encoded=getenv($env);
        $key=is_string($encoded)?base64_decode($encoded,true):false;
        if(!is_string($key)||strlen($key)!==32)throw new RuntimeException('KEY_MISSING');
        return $key;
    };
    $db=mos_test_connection();
    $q=$db->prepare('SELECT id FROM mos_workspace WHERE id=?');
    $q->execute([(string)$workspace]);
    if($q->fetchColumn()===false)throw new RuntimeException('UNKNOWN_WORKSPACE');
    $manager=new MosWireQuarantineKeyManager($db);
    $queue='mos_identity_'.(string)$workspace;
    // Interlock with the WIRE_SCAN worker's advisory lock. The caller should
    // additionally disable scheduled supervisors during planned key rotation.
    $lockName='mos_worker_'.substr(hash('sha256',$queue.'_WIRE_SCAN'),0,36);
    $lock=$db->prepare('SELECT GET_LOCK(?,0)');
    $lock->execute([$lockName]);
    if((int)$lock->fetchColumn()!==1)throw new RuntimeException('DLQ_MAINTENANCE_BUSY');
    try {
        if($operation==='INVENTORY'){
            if($argc!==4)throw new InvalidArgumentException('INVALID_ARGS');
            $numeric($argv[3],1,100);
            $out=$manager->inventory($workspace);
        }elseif($operation==='VERIFY'){
            if($argc!==6)throw new InvalidArgumentException('INVALID_ARGS');
            $limit=$numeric($argv[4],1,100);
            $cursor=$numeric($argv[5],0,2147483647);
            $out=$manager->verify($workspace,$argv[3],$readKey('MOS_DLQ_OLD_KEY_B64'),$limit,$cursor);
        }else{
            if(getenv('MOS_DLQ_ROTATE_APPROVED')!=='1')throw new RuntimeException('ROTATION_NOT_APPROVED');
            if($argc!==6)throw new InvalidArgumentException('INVALID_ARGS');
            $limit=$numeric($argv[5],1,100);
            $out=$manager->rotate($workspace,$argv[3],$readKey('MOS_DLQ_OLD_KEY_B64'),
                                  $argv[4],$readKey('MOS_DLQ_NEW_KEY_B64'),$limit);
        }
        echo json_encode(['mode'=>$operation,'workspace_id'=>(string)$workspace,
                          'result'=>$out],JSON_THROW_ON_ERROR)."\n";
    }finally {
        $release=$db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}catch(Throwable $error){
    $known=['DISABLED','INVALID_ARGS','LIMIT_EXCEEDED','KEY_MISSING',
      'UNKNOWN_WORKSPACE','DLQ_MAINTENANCE_BUSY','ROTATION_NOT_APPROVED',
      'ARCHIVE_AUTHENTICATION_FAILED','ARCHIVE_SOURCE_MISMATCH'];
    $reason=in_array($error->getMessage(),$known,true)?$error->getMessage():'DLQ_MAINTENANCE_FAILED';
    fwrite(STDERR,"MOS DLQ maintenance refused: ".$reason."\n");
    exit(2);
}