<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Transport;

use PDO;
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Contracts\Id\EventId;

/** Records only safe, fixed failure classification; never raw handler errors. */
final readonly class MosFailureLedger {
    public function __construct(private PDO $db){}
    public function quarantined(WorkspaceId $workspace,EventId $event,string $consumer):bool {
        $q=$this->db->prepare('SELECT quarantined_at FROM mos_messenger_failure WHERE workspace_id=? AND event_id=? AND consumer_key=?');
        $q->execute([(string)$workspace,(string)$event,$consumer]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        return $row!==false && $row['quarantined_at']!==null;
    }
    public function recordFailure(WorkspaceId $workspace,EventId $event,string $consumer,int $maxAttempts):bool {
        if($maxAttempts<1||$maxAttempts>25)throw new \InvalidArgumentException('Unsafe maximum attempts.');
        $this->db->beginTransaction();
        try {
            // Lock the immutable domain-event row to serialize competing attempts
            // even when no failure ledger row exists yet.
            $s=$this->db->prepare('SELECT event_id FROM mos_domain_event WHERE workspace_id=? AND event_id=? FOR UPDATE');
            $s->execute([(string)$workspace,(string)$event]);
            if($s->fetchColumn()===false)throw new \RuntimeException('Unknown event for failure ledger.');
            $s=$this->db->prepare('SELECT attempts,quarantined_at FROM mos_messenger_failure WHERE workspace_id=? AND event_id=? AND consumer_key=? FOR UPDATE');
            $s->execute([(string)$workspace,(string)$event,$consumer]);
            $row=$s->fetch(PDO::FETCH_ASSOC);
            $attempts=($row===false?0:(int)$row['attempts'])+1;
            $quarantine=$attempts >= $maxAttempts || ($row!==false && $row['quarantined_at']!==null);
            $s=$this->db->prepare('INSERT INTO mos_messenger_failure
                (workspace_id,event_id,consumer_key,attempts,last_error_code,quarantined_at,updated_at)
                VALUES(?,?,?,?,?,IF(?=1,UTC_TIMESTAMP(6),NULL),UTC_TIMESTAMP(6))
                ON DUPLICATE KEY UPDATE attempts=VALUES(attempts),last_error_code=VALUES(last_error_code),
                quarantined_at=IF(VALUES(quarantined_at) IS NULL,quarantined_at,COALESCE(quarantined_at,VALUES(quarantined_at))),
                updated_at=UTC_TIMESTAMP(6)');
            $s->execute([(string)$workspace,(string)$event,$consumer,$attempts,'HANDLER_FAILED',$quarantine?1:0]);
            $this->db->commit();
            return $quarantine;
        }catch(\Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }
}
