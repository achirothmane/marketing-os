<?php
declare(strict_types=1);
require dirname(__DIR__).'/tests/bootstrap.php';

use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Bridge\{
    PdoMauticContactSnapshotReader,
    PdoMauticContactBridge,
    PdoMauticContactReconciler,
    BoundedShadowScanWorker
};
use MarketingOS\Identity\Persistence\PdoCanonicalContactImporter;

try{
    // Both gates are required: never enable by merely installing the code.
    if(getenv('MOS_BRIDGE_MODE')!=='SHADOW' ||
       getenv('MOS_SCHEDULED_SHADOW_ENABLED')!=='1') {
        throw new RuntimeException('DISABLED');
    }
    if($argc!==2)throw new InvalidArgumentException('One explicit Workspace UUID argument required.');
    $workspace=WorkspaceId::fromString($argv[1]);
    $key=getenv('MOS_SNAPSHOT_HMAC_KEY');
    if(!is_string($key)||strlen($key)<32)throw new RuntimeException('SECRET_REQUIRED');
    $intEnv=static function(string $name,int $default,int $min,int $max):int {
        $raw=getenv($name);
        if($raw===false)return $default;
        if(!preg_match('/^[1-9][0-9]*$/D',$raw))throw new InvalidArgumentException('Bad positive integer setting.');
        $value=(int)$raw;
        if($value<$min || $value>$max)throw new InvalidArgumentException('Scan cap out of range.');
        return $value;
    };
    $batchSize=$intEnv('MOS_SCAN_BATCH_SIZE',100,1,1000);
    $maxBatches=$intEnv('MOS_SCAN_MAX_BATCHES',5,1,100);
    $maxSeconds=$intEnv('MOS_SCAN_MAX_SECONDS',30,1,300);
    $db=mos_test_connection(); // explicit DB credentials only; no default connection.
    $reader=new PdoMauticContactSnapshotReader($db,$key,getenv('MOS_MAUTIC_TABLE_PREFIX')?:'');
    $bridge=new PdoMauticContactBridge($db,$reader,new PdoCanonicalContactImporter($db));
    $worker=new BoundedShadowScanWorker(new PdoMauticContactReconciler($db,$reader,$bridge));
    $result=$worker->run($workspace,$batchSize,$maxBatches,$maxSeconds);
    // No PII, secret or source row payloads. Exit 2 demands human reconciliation.
    echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
    if($result['blocked']>0)exit(2);
}catch(Throwable $e){
    fwrite(STDERR,"MOS scheduled shadow scan refused or failed.\n");
    exit(1);
}