<?php
declare(strict_types=1);
require dirname(__DIR__).'/tests/bootstrap.php';

use MarketingOS\Contracts\Id\WorkspaceId;

/**
 * C4-08B: bounded, explicitly invoked SHADOW source→queue coordinator.
 * This does NOT install a scheduler or authorize external effects.
 *
 * Single workspace. Source conflicts block downstream queue execution.
 * Durable Outbox and Inbox provide replay safety if a child stops.
 */
function mosPipelineChild(string $script,array $args,int $timeoutSeconds,array $envOverrides=[]):array {
    $proc=proc_open(array_merge([PHP_BINARY,$script],$args),
      [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),$envOverrides));
    if(!is_resource($proc))throw new RuntimeException('CHILD_START_FAILURE');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1],false);
    stream_set_blocking($pipes[2],false);
    $out='';$err='';$started=microtime(true);$expired=false;$lastExit=-1;
    while(true){
        $out.=stream_get_contents($pipes[1]);
        $err.=stream_get_contents($pipes[2]);
        // Never put arbitrary child output in this coordinator's error response.
        if(strlen($out)>65536||strlen($err)>65536){
            proc_terminate($proc,9);
            $expired=true;break;
        }
        $status=proc_get_status($proc);
        if(!$status['running']){$lastExit=(int)$status['exitcode'];break;}
        if(microtime(true)-$started>$timeoutSeconds){
            proc_terminate($proc,15);
            usleep(100000);
            $status=proc_get_status($proc);
            if($status['running'])proc_terminate($proc,9);
            $expired=true;break;
        }
        usleep(30000);
    }
    $out.=stream_get_contents($pipes[1]);
    $err.=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    $closed=proc_close($proc);
    $exit=$expired?124:($lastExit>=0?$lastExit:$closed);
    return [$exit,trim($out)];
}
try{
    // Five independent operator opt-ins; no hidden cron installed.
    if(getenv('MOS_PIPELINE_ENABLED')!=='1' ||
       getenv('MOS_BRIDGE_MODE')!=='SHADOW' ||
       getenv('MOS_SCHEDULED_SHADOW_ENABLED')!=='1' ||
       getenv('MOS_SUPERVISOR_ENABLED')!=='1' ||
       getenv('MOS_QUEUE_ENABLED')!=='1' ||
       getenv('MOS_QUEUE_MODE')!=='SHADOW') {
        throw new RuntimeException('DISABLED');
    }
    if($argc!==6)throw new InvalidArgumentException('INVALID_ARGS');
    $workspace=WorkspaceId::fromString($argv[1]);
    $number=static function(string $text,int $min,int $max):int{
        if(!preg_match('/^[1-9][0-9]*$/D',$text) || (int)$text<$min || (int)$text>$max){
            throw new InvalidArgumentException('LIMIT_EXCEEDED');
        }
        return (int)$text;
    };
    $scanBatch=$number($argv[2],1,1000);
    $scanBatches=$number($argv[3],1,10);
    $queueCycles=$number($argv[4],1,3);
    $queueBudget=$number($argv[5],1,20);
    $sourceKey=getenv('MOS_SNAPSHOT_HMAC_KEY');
    if(!is_string($sourceKey)||strlen($sourceKey)<32)throw new RuntimeException('SOURCE_KEY_MISSING');
    $encoded=getenv('MOS_DLQ_KEY_B64');
    $dlqKey=is_string($encoded)?base64_decode($encoded,true):false;
    if(!is_string($dlqKey)||strlen($dlqKey)!==32)throw new RuntimeException('DLQ_KEY_MISSING');
    $db=mos_test_connection();
    $exists=$db->prepare('SELECT id FROM mos_workspace WHERE id=?');
    $exists->execute([(string)$workspace]);
    if($exists->fetchColumn()===false)throw new RuntimeException('UNKNOWN_WORKSPACE');
    $lockName='mos_pipeline_'.substr(hash('sha256',(string)$workspace),0,37);
    $lock=$db->prepare('SELECT GET_LOCK(?,0)');
    $lock->execute([$lockName]);
    if((int)$lock->fetchColumn()!==1)throw new RuntimeException('PIPELINE_BUSY');
    try{
        // Reuse existing opt-in source sweep without rewriting its cursor contract.
        // A failed child may already have committed Person/Outbox, but never
        // produces a false pipeline success receipt.
        $scanScript=__DIR__.'/run_scheduled_shadow_scan.php';
        [$scanExit,$scanText]=mosPipelineChild($scanScript,[(string)$workspace],90,[
           'MOS_SCAN_BATCH_SIZE'=>(string)$scanBatch,
           'MOS_SCAN_MAX_BATCHES'=>(string)$scanBatches,
           'MOS_SCAN_MAX_SECONDS'=>'30'
        ]);
        if($scanExit!==0)throw new RuntimeException('SOURCE_SCAN_FAILED');
        $scan=json_decode($scanText,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($scan)||
           !isset($scan['batches'],$scan['seen'],$scan['created'],$scan['reused'],$scan['blocked']) ||
           !is_int($scan['blocked'])||$scan['blocked']!==0 ||
           !is_int($scan['batches'])||$scan['batches']>$scanBatches){
            throw new RuntimeException('SOURCE_RECONCILIATION_REQUIRED');
        }
        // Validates that the child respected caller caps, not just its defaults.
        $queueScript=__DIR__.'/messenger_shadow_supervisor.php';
        [$queueExit,$queueText]=mosPipelineChild($queueScript,
           [(string)$workspace,(string)$queueCycles,(string)$queueBudget],120);
        if($queueExit!==0)throw new RuntimeException('QUEUE_CYCLE_FAILED');
        $queue=json_decode($queueText,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($queue)||
           ($queue['mode']??null)!=='BOUNDED_SUPERVISOR'||
           ($queue['workspace_id']??null)!==(string)$workspace||
           !is_array($queue['counters']??null)||
           ($queue['counters']['cycles']??null)!==$queueCycles){
            throw new RuntimeException('INVALID_QUEUE_RECEIPT');
        }
        $receipt=[
            'mode'=>'BOUNDED_SHADOW_PIPELINE',
            'workspace_id'=>(string)$workspace,
            'source'=>[
              'batches'=>(int)$scan['batches'],'seen'=>(int)$scan['seen'],
              'created'=>(int)$scan['created'],'reused'=>(int)$scan['reused'],
              'blocked'=>0,'wrapped'=>(bool)($scan['wrapped']??false),
            ],
            'queue'=>$queue['counters'],
            'complete_source_sweep'=>(bool)($scan['wrapped']??false)
        ];
        echo json_encode($receipt,JSON_THROW_ON_ERROR)."\n";
    }finally{
        $release=$db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}catch(Throwable $error){
    // PII-safe, stable errors only. Never emit raw SQL, Contact values or child output.
    $allowed=['DISABLED','INVALID_ARGS','LIMIT_EXCEEDED','SOURCE_KEY_MISSING',
      'DLQ_KEY_MISSING','UNKNOWN_WORKSPACE','PIPELINE_BUSY',
      'CHILD_START_FAILURE','SOURCE_SCAN_FAILED','SOURCE_RECONCILIATION_REQUIRED',
      'QUEUE_CYCLE_FAILED','INVALID_QUEUE_RECEIPT'];
    $reason=in_array($error->getMessage(),$allowed,true)
        ?$error->getMessage():'PIPELINE_FAILURE';
    fwrite(STDERR,"MOS bounded SHADOW pipeline blocked: ".$reason."\n");
    exit(2);
}