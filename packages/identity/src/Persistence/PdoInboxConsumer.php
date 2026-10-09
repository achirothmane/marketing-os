<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Persistence;

use PDO;
use PDOException;
use InvalidArgumentException;
use RuntimeException;
use MarketingOS\Contracts\Id\EventId;
use MarketingOS\Contracts\Id\WorkspaceId;

/** Inbox dedup marker and business projection commit in one local transaction. */
final readonly class PdoInboxConsumer {
    public function __construct(private PDO $db) {}

    /**
     * @param callable(array,PDO):void $apply
     * @return bool True if projection applied; false if duplicate already processed.
     */
    public function consume(WorkspaceId $workspace,EventId $eventId,string $consumerKey,callable $apply): bool {
        if(!preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D',$consumerKey))
            throw new InvalidArgumentException('Invalid stable consumer key.');
        if($this->db->inTransaction())throw new RuntimeException('Nested inbox transaction forbidden.');
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare("SELECT event_type,payload_json FROM mos_domain_event
                WHERE workspace_id=? AND event_id=?");
            $q->execute([(string)$workspace,(string)$eventId]);
            $event=$q->fetch(PDO::FETCH_ASSOC);
            if($event===false)throw new InvalidArgumentException('Unknown event or workspace.');
            try{
                $s=$this->db->prepare("INSERT INTO mos_inbox(workspace_id,event_id,consumer_key,processed_at)
                    VALUES(?,?,?,UTC_TIMESTAMP(6))");
                $s->execute([(string)$workspace,(string)$eventId,$consumerKey]);
            }catch(PDOException $e) {
                if($this->db->inTransaction())$this->db->rollBack();
                if($e->getCode()==='23000' && isset($e->errorInfo[1]) && (int)$e->errorInfo[1]===1062)
                    return false;
                throw $e;
            }
            $apply(['workspace_id'=>(string)$workspace,'event_id'=>(string)$eventId,
                'event_type'=>$event['event_type'],
                'payload'=>json_decode($event['payload_json'],true,512,JSON_THROW_ON_ERROR)],$this->db);
            $this->db->commit();
            return true;
        }catch(\Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }
}
