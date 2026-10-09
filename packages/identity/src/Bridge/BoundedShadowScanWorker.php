<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Bridge;

use InvalidArgumentException;
use MarketingOS\Contracts\Id\WorkspaceId;

/**
 * Bounded orchestration ONLY: never sends mail, changes source Contacts or
 * claims proof of a complete snapshot. Intended for opt-in cron invocation.
 */
final readonly class BoundedShadowScanWorker {
    public function __construct(private PdoMauticContactReconciler $reconciler) {}

    /** @return array{batches:int,seen:int,created:int,reused:int,blocked:int,wrapped:bool,reason_counts:array<string,int>} */
    public function run(
        WorkspaceId $workspace,
        int $batchSize=100,
        int $maxBatches=5,
        int $maxSeconds=30
    ):array {
        if($batchSize<1||$batchSize>1000 || $maxBatches<1||$maxBatches>100
           || $maxSeconds<1||$maxSeconds>300) {
            throw new InvalidArgumentException('Invalid bounded scan limits.');
        }
        $started=hrtime(true);
        $result=[
            'batches'=>0,'seen'=>0,'created'=>0,'reused'=>0,'blocked'=>0,
            'wrapped'=>false,'reason_counts'=>[],
        ];
        for($n=0;$n<$maxBatches;$n++){
            // Wall-clock budget is cooperative between batches, not a hard
            // interruption inside a DB transaction. A batch may exceed it.
            if($n>0 && hrtime(true)-$started >= $maxSeconds*1_000_000_000)break;
            $step=$this->reconciler->scanOnce($workspace,$batchSize);
            $result['batches']++;
            foreach(['seen','created','reused','blocked'] as $field)$result[$field]+=$step[$field];
            foreach($step['reason_counts'] as $reason=>$count){
                $result['reason_counts'][$reason]=($result['reason_counts'][$reason]??0)+$count;
            }
            if($step['wrapped']){
                $result['wrapped']=true;
                break; // stop after exactly one end-of-sweep per invocation
            }
        }
        return $result;
    }
}