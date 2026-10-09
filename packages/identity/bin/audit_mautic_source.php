<?php
declare(strict_types=1);
require dirname(__DIR__).'/tests/bootstrap.php';

use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Bridge\PdoMauticContactSnapshotReader;
use MarketingOS\Identity\Bridge\PdoMauticSourceAuditor;

try{
    if(getenv('MOS_BRIDGE_MODE')!=='SHADOW' || getenv('MOS_SOURCE_AUDIT_ENABLED')!=='1'){
        throw new RuntimeException('SOURCE_AUDIT_OFF');
    }
    if($argc<2 || $argc>4)throw new InvalidArgumentException('Workspace, optional afterId and limit required.');
    $workspace=WorkspaceId::fromString($argv[1]);
    $afterId=$argv[2]??'0';$limit=$argv[3]??'100';
    if(!preg_match('/^(0|[1-9][0-9]*)$/D',$afterId) ||
        !preg_match('/^[1-9][0-9]*$/D',$limit) ||
        (int)$limit>500)throw new InvalidArgumentException('Invalid bounded audit arguments.');
    $key=getenv('MOS_SNAPSHOT_HMAC_KEY');
    if(!is_string($key)||strlen($key)<32)throw new RuntimeException('HMAC secret required.');
    $db=mos_test_connection();
    $reader=new PdoMauticContactSnapshotReader($db,$key,getenv('MOS_MAUTIC_TABLE_PREFIX')?:'');
    $auditor=new PdoMauticSourceAuditor($db,$reader);
    $report=$auditor->auditMappedBatch($workspace,(int)$afterId,(int)$limit);
    $report['review_required']=array_sum(array_diff_key($report['status_counts'],['MATCH'=>true]))>0;
    echo json_encode($report,JSON_THROW_ON_ERROR)."\n";
    // No automatic resolution. Exit 2 means operator review required.
    if($report['review_required'])exit(2);
}catch(Throwable $error){
    // Never echo source PII, key material or SQL errors.
    fwrite(STDERR,"MOS source audit refused or failed.\n");
    exit(1);
}