<?php
declare(strict_types=1);
require dirname(__DIR__).'/tests/bootstrap.php';
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Bridge\PdoMauticContactSnapshotReader;
use MarketingOS\Identity\Bridge\PdoMauticContactBridge;
use MarketingOS\Identity\Persistence\PdoCanonicalContactImporter;
try{
    if(getenv('MOS_BRIDGE_MODE')!=='SHADOW')throw new RuntimeException('DISABLED');
    if($argc!==3||!preg_match('/^[1-9][0-9]*$/D',$argv[2]??''))throw new InvalidArgumentException('USAGE_ERROR');
    $key=getenv('MOS_SNAPSHOT_HMAC_KEY');
    if(!is_string($key)||strlen($key)<32)throw new RuntimeException('MISSING_KEY');
    $db=mos_test_connection();
    $w=WorkspaceId::fromString($argv[1]);
    $reader=new PdoMauticContactSnapshotReader($db,$key,getenv('MOS_MAUTIC_TABLE_PREFIX')?:'');
    $r=(new PdoMauticContactBridge($db,$reader,new PdoCanonicalContactImporter($db)))
      ->importPersisted($w,(int)$argv[2]);
    echo json_encode(['created'=>$r->created,'person_id'=>(string)$r->mapping->personId,
      'event_id'=>$r->eventId===null?null:(string)$r->eventId],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){
    $known=['CONTACT_NOT_FOUND_OR_NOT_PERSISTED','LEGACY_MAPPING_LACKS_EVIDENCE',
      'EVIDENCE_SOURCE_CONFLICT','CHANGED_CONTACT_NEEDS_C5_RECONCILIATION','CONCURRENT_SNAPSHOT_CONFLICT'];
    fwrite(STDERR,"MOS contact shadow import blocked: ".
      (in_array($e->getMessage(),$known,true)?$e->getMessage():'INVALID_CONFIGURATION_OR_IMPORT')."\n");
    exit(1);
}
