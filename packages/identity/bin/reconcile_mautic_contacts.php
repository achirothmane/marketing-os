<?php
declare(strict_types=1);
require dirname(__DIR__).'/tests/bootstrap.php';
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Bridge\PdoMauticContactSnapshotReader;
use MarketingOS\Identity\Bridge\PdoMauticContactBridge;
use MarketingOS\Identity\Bridge\PdoMauticContactReconciler;
use MarketingOS\Identity\Persistence\PdoCanonicalContactImporter;
try{
    if(getenv('MOS_BRIDGE_MODE')!=='SHADOW')throw new RuntimeException('SHADOW_MODE_REQUIRED');
    if($argc<2||$argc>3)throw new InvalidArgumentException('WORKSPACE_UUID and optional BATCH_SIZE required.');
    $workspace=WorkspaceId::fromString($argv[1]);
    $batch=isset($argv[2])?(int)$argv[2]:100;
    $key=getenv('MOS_SNAPSHOT_HMAC_KEY');
    if(!is_string($key)||strlen($key)<32)throw new RuntimeException('HMAC_KEY_REQUIRED');
    $db=mos_test_connection();
    $reader=new PdoMauticContactSnapshotReader($db,$key,getenv('MOS_MAUTIC_TABLE_PREFIX')?:'');
    $bridge=new PdoMauticContactBridge($db,$reader,new PdoCanonicalContactImporter($db));
    $result=(new PdoMauticContactReconciler($db,$reader,$bridge))->scanOnce($workspace,$batch);
    echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
    // Blocked contacts need operator reconciliation; don't silently report green.
    if($result['blocked']>0)exit(2);
}catch(Throwable $e){
    // Stable diagnostic only; do not leak source PII, SQL or HMAC secrets.
    fwrite(STDERR,"MOS committed Contact sweep refused.\n");
    exit(1);
}
