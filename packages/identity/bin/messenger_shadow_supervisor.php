<?php
declare(strict_types=1);
require dirname(__DIR__).'/tests/bootstrap.php';
use MarketingOS\Contracts\Id\WorkspaceId;

/**
 * Explicit, finite SHADOW coordinator. Not a deployed background daemon.
 * Each step runs in an isolated PHP process, with bounded one-time restart.
 */
try {
    if(getenv('MOS_SUPERVISOR_ENABLED')!=='1' ||
       getenv('MOS_QUEUE_ENABLED')!=='1' ||
       getenv('MOS_QUEUE_MODE')!=='SHADOW')throw new RuntimeException('DISABLED');
    if($argc!==4 || !preg_match('/^[1-9][0-9]*$/D',$argv[2]) ||
       !preg_match('/^[1-9][0-9]*$/D',$argv[3]))throw new InvalidArgumentException('INVALID_ARGS');
    $workspace=WorkspaceId::fromString($argv[1]);
    $cycles=(int)$argv[2];$budget=(int)$argv[3];
    if($cycles>10||$budget>20)throw new InvalidArgumentException('LIMIT_EXCEEDED');
    $key=getenv('MOS_DLQ_KEY_B64');
    $rawKey=is_string($key)?base64_decode($key,true):false;
    if(!is_string($rawKey)||strlen($rawKey)!==32)throw new RuntimeException('DLQ_KEY_MISSING');
    $worker=dirname(__FILE__).'/messenger_shadow_worker.php';
    $counts=['cycles'=>0,'wire_scanned'=>0,'wire_quarantined'=>0,
        'published'=>0,'processed'=>0,'replayed'=>0,'quarantined'=>0,'restarts'=>0];
    for($cycle=0;$cycle<$cycles;$cycle++){
        foreach(['WIRE_SCAN','PUBLISH','CONSUME'] as $step){
            $lastFailure=false;
            for($attempt=0;$attempt<2;$attempt++){
                // proc_open array form avoids shell parsing/injection.
                $p=proc_open([PHP_BINARY,$worker,(string)$workspace,$step,(string)$budget],
                    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                if(!is_resource($p))throw new RuntimeException('CHILD_START_FAILURE');
                fclose($pipes[0]);
                stream_set_blocking($pipes[1],false);
                stream_set_blocking($pipes[2],false);
                $start=microtime(true);$stdout='';$stderr='';$expired=false;
                do {
                    $stdout.=stream_get_contents($pipes[1]);
                    $stderr.=stream_get_contents($pipes[2]);
                    $state=proc_get_status($p);
                    if(!$state['running'])break;
                    if(microtime(true)-$start>45){
                        proc_terminate($p,15);
                        usleep(100000);
                        $state=proc_get_status($p);
                        if($state['running'])proc_terminate($p,9);
                        $expired=true;break;
                    }
                    usleep(30000);
                }while(true);
                $stdout.=stream_get_contents($pipes[1]);
                $stderr.=stream_get_contents($pipes[2]);
                fclose($pipes[1]);fclose($pipes[2]);
                proc_close($p);
                $exitCode=$expired?124:(int)($state['exitcode']??-1);
                if($exitCode===0){
                    $result=json_decode(trim($stdout),true,16,JSON_THROW_ON_ERROR);
                    if(!is_array($result)||($result['mode']??null)!==$step ||
                       ($result['workspace_id']??null)!==(string)$workspace ||
                       !is_array($result['counters']??null)){
                        throw new RuntimeException('INVALID_CHILD_RECEIPT');
                    }
                    $data=$result['counters'];
                    if($step==='WIRE_SCAN'){
                        $scan=$data['wire_scan']??[];
                        $counts['wire_scanned']+=(int)($scan['scanned']??0);
                        $counts['wire_quarantined']+=(int)($scan['quarantined']??0);
                        if(($scan['oversized']??0)>0)throw new RuntimeException('WIRE_REQUIRES_MANUAL_REVIEW');
                    }else{
                        foreach(['published','processed','replayed','quarantined'] as $k){
                            $counts[$k]+=(int)($data[$k]??0);
                        }
                    }
                    $lastFailure=false;
                    break;
                }
                $lastFailure=true;
                if($attempt===0)$counts['restarts']++;
            }
            if($lastFailure)throw new RuntimeException('CHILD_RETRY_EXHAUSTED');
        }
        $counts['cycles']++;
    }
    echo json_encode(['mode'=>'BOUNDED_SUPERVISOR','workspace_id'=>(string)$workspace,
        'counters'=>$counts],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $error){
    // We intentionally emit NO raw exception message, SQL, queue bytes or secret.
    $known=['DISABLED','INVALID_ARGS','LIMIT_EXCEEDED','DLQ_KEY_MISSING',
        'CHILD_START_FAILURE','CHILD_RETRY_EXHAUSTED','WIRE_REQUIRES_MANUAL_REVIEW',
        'INVALID_CHILD_RECEIPT'];
    $reason=in_array($error->getMessage(),$known,true)?$error->getMessage():'SUPERVISOR_FAILURE';
    fwrite(STDERR,"MOS bounded supervisor blocked: ".$reason."\n");
    exit(2);
}