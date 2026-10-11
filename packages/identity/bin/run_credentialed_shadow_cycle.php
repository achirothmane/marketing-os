<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/runtime.php';

use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Runtime\MosCredentialDirectory;
use MarketingOS\Identity\Runtime\MosShadowReadinessPreflight;

/**
 * C4-07B3B3A: one bounded SHADOW cycle for an external supervisor/timer.
 * Default OFF. No listener, mail, ads, provider effects or DLQ re-drive.
 */
function mosServicePipeline(WorkspaceId $workspace,int $timeout=245):array {
    // No test-only connection variables or credential-directory path enters
    // worker children. Process environment does contain the four credentials
    // for existing bounded CLI consumers; OS account isolation is still needed.
    $env=getenv();
    foreach(array_keys($env) as $key) {
        if(str_starts_with($key,'MOS_TEST_')||str_starts_with($key,'MOS_CI_')||
           $key==='CREDENTIALS_DIRECTORY')unset($env[$key]);
    }
    $proc=proc_open([PHP_BINARY,__DIR__.'/run_shadow_pipeline.php',
        (string)$workspace,'100','4','1','10'],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
    if(!is_resource($proc))throw new RuntimeException('SERVICE_CHILD_START_FAILED');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
    $start=microtime(true);$out='';$err='';$lastExit=-1;$expired=false;
    try {
        while(true) {
            $out.=stream_get_contents($pipes[1]);
            $err.=stream_get_contents($pipes[2]);
            if(strlen($out)>65536||strlen($err)>65536) {
                $expired=true;proc_terminate($proc,9);break;
            }
            $state=proc_get_status($proc);
            if(!$state['running']) {$lastExit=(int)$state['exitcode'];break;}
            if(microtime(true)-$start>$timeout) {
                $expired=true;proc_terminate($proc,15);
                usleep(100000);
                $state=proc_get_status($proc);
                if($state['running'])proc_terminate($proc,9);
                break;
            }
            usleep(30000);
        }
        $out.=stream_get_contents($pipes[1]);
        $err.=stream_get_contents($pipes[2]);
    } finally {
        fclose($pipes[1]);fclose($pipes[2]);
        $closed=proc_close($proc);
    }
    $status=$expired?124:($lastExit>=0?$lastExit:$closed);
    if($status!==0)throw new RuntimeException('SERVICE_PIPELINE_BLOCKED');
    $result=json_decode(trim($out),true,24,JSON_THROW_ON_ERROR);
    if(!is_array($result)||($result['mode']??null)!=='BOUNDED_SHADOW_PIPELINE'||
       ($result['workspace_id']??null)!==(string)$workspace||
       !is_array($result['source']??null)||!is_array($result['queue']??null)||
       ($result['queue']['cycles']??null)!==1) {
        throw new RuntimeException('SERVICE_RECEIPT_INVALID');
    }
    return $result;
}
try {
    foreach([
        'MOS_SERVICE_ENABLED'=>'1','MOS_PREFLIGHT_ENABLED'=>'1',
        'MOS_PIPELINE_ENABLED'=>'1','MOS_BRIDGE_MODE'=>'SHADOW',
        'MOS_SCHEDULED_SHADOW_ENABLED'=>'1','MOS_SUPERVISOR_ENABLED'=>'1',
        'MOS_QUEUE_ENABLED'=>'1','MOS_QUEUE_MODE'=>'SHADOW'
    ] as $key=>$expected) {
        if(getenv($key)!==$expected)throw new RuntimeException('SERVICE_DISABLED');
    }
    if($argc!==2)throw new RuntimeException('SERVICE_INVALID_ARGS');
    $workspace=WorkspaceId::fromString($argv[1]);
    $keyId=getenv('MOS_DLQ_ACTIVE_KEY_ID');
    if(!is_string($keyId)||!preg_match('/^[A-Za-z0-9_.-]{1,32}$/D',$keyId)) {
        throw new RuntimeException('SERVICE_KEY_ID_INVALID');
    }
    $credentials=MosCredentialDirectory::load();
    foreach($credentials as $name=>$secret)putenv($name.'='.$secret);
    $wireKey=base64_decode($credentials['MOS_DLQ_KEY_B64'],true);
    if(!is_string($wireKey)||strlen($wireKey)!==32) {
        throw new RuntimeException('SERVICE_CREDENTIAL_INVALID');
    }
    $read=mos_runtime_connection('READ');
    $write=mos_runtime_connection('WRITE');
    $lockName='mos_service_'.substr(hash('sha256',(string)$workspace),0,37);
    $q=$write->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lockName]);
    if((int)$q->fetchColumn()!==1)throw new RuntimeException('SERVICE_ALREADY_RUNNING');
    try {
        // Check with least-privileged READ account before any write-capable child.
        $checker=new MosShadowReadinessPreflight($read);
        $before=$checker->assess($workspace,$keyId,$wireKey,100,10);
        if($before['decision']!=='LOCAL_PREFLIGHT_PASS') {
            echo json_encode([
                'mode'=>'SUPERVISED_SHADOW_CYCLE','workspace_id'=>(string)$workspace,
                'decision'=>'REVIEW_REQUIRED','phase'=>'BEFORE',
                'reasons'=>$before['review_reasons']
            ],JSON_THROW_ON_ERROR)."\n";
            $code=3;
        } else {
            $pipeline=mosServicePipeline($workspace);
            // Another scan may have quarantined invalid wire. Never silently
            // report production-ready status based on the initial preflight.
            $after=$checker->assess($workspace,$keyId,$wireKey,100,10);
            $review=$after['decision']!=='LOCAL_PREFLIGHT_PASS';
            echo json_encode([
                'mode'=>'SUPERVISED_SHADOW_CYCLE','workspace_id'=>(string)$workspace,
                'decision'=>$review?'REVIEW_REQUIRED':'LOCAL_SHADOW_CYCLE_PASS',
                'phase'=>'AFTER',
                'source'=>$pipeline['source'],
                'queue'=>$pipeline['queue'],
                'reasons'=>$review?$after['review_reasons']:[]
            ],JSON_THROW_ON_ERROR)."\n";
            $code=$review?3:0;
        }
    } finally {
        $q=$write->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);
    }
    exit($code);
} catch(Throwable $e) {
    $allow=['SERVICE_DISABLED','SERVICE_INVALID_ARGS','SERVICE_KEY_ID_INVALID',
      'CREDENTIAL_DIRECTORY_MISSING','CREDENTIAL_DIRECTORY_UNSAFE',
      'CREDENTIAL_ENV_COLLISION','CREDENTIAL_FILE_UNSAFE',
      'CREDENTIAL_FILE_INVALID','SERVICE_CREDENTIAL_INVALID',
      'SERVICE_ALREADY_RUNNING','SERVICE_PIPELINE_BLOCKED','SERVICE_RECEIPT_INVALID',
      'SERVICE_CHILD_START_FAILED','RUNTIME_CREDENTIALS_MISSING',
      'RUNTIME_DSN_INVALID','RUNTIME_CONNECTION_REFUSED'];
    $reason=in_array($e->getMessage(),$allow,true)?$e->getMessage():'SERVICE_REFUSED';
    fwrite(STDERR,"MOS supervised SHADOW cycle blocked: ".$reason."\n");
    exit(2);
}
