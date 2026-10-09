<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Persistence;

use PDO;
use RuntimeException;
use MarketingOS\Contracts\Id\UuidV7;
use MarketingOS\Contracts\Id\WorkspaceId;

/**
 * At-least-once transport relay. Must NEVER assume an ACK-lost delivery
 * did not reach a consumer. The transport adapter supplies $deliver.
 */
final readonly class PdoOutboxPublisher {
    public function __construct(private PDO $db) {}

    /** @param callable(array):void $deliver */
    public function publishOne(callable $deliver,int $leaseSeconds=30, ?WorkspaceId $onlyWorkspace=null): ?string {
        if($leaseSeconds<1||$leaseSeconds>3600)throw new \InvalidArgumentException('Invalid lease duration.');
        $q=$this->db->prepare("SELECT workspace_id,event_id FROM mos_outbox
            WHERE published_at IS NULL AND (lease_expires_at IS NULL OR lease_expires_at<=UTC_TIMESTAMP(6))".
            ($onlyWorkspace!==null?" AND workspace_id=?":"")."
            ORDER BY created_at,event_id LIMIT 12");
        $q->execute($onlyWorkspace!==null?[(string)$onlyWorkspace]:[]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            $owner=(string)UuidV7::generate();
            $s=$this->db->prepare("UPDATE mos_outbox SET lease_owner=?,
                lease_expires_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL $leaseSeconds SECOND),
                attempts=attempts+1
                WHERE workspace_id=? AND event_id=? AND published_at IS NULL
                AND (lease_expires_at IS NULL OR lease_expires_at<=UTC_TIMESTAMP(6))");
            $s->execute([$owner,$candidate['workspace_id'],$candidate['event_id']]);
            if($s->rowCount()!==1)continue;
            $read=$this->db->prepare("SELECT e.workspace_id,e.event_id,e.event_type,e.schema_version,
                e.aggregate_type,e.aggregate_id,e.aggregate_version,e.evidence_id,e.payload_json
                FROM mos_domain_event e WHERE e.workspace_id=? AND e.event_id=?");
            $read->execute([$candidate['workspace_id'],$candidate['event_id']]);
            $event=$read->fetch(PDO::FETCH_ASSOC);
            if($event===false)throw new RuntimeException('Outbox references missing domain event.');
            $message=[
                'workspace_id'=>$event['workspace_id'],
                'event_id'=>$event['event_id'],
                'event_type'=>$event['event_type'],
                'schema_version'=>(int)$event['schema_version'],
                'aggregate_type'=>$event['aggregate_type'],
                'aggregate_id'=>$event['aggregate_id'],
                'aggregate_version'=>(int)$event['aggregate_version'],
                'evidence_id'=>$event['evidence_id'],
                'payload'=>json_decode($event['payload_json'],true,512,JSON_THROW_ON_ERROR),
            ];
            try {
                // Send can succeed remotely and then throw locally (lost ACK).
                $deliver($message);
            } catch(\Throwable $e) {
                $release=$this->db->prepare("UPDATE mos_outbox SET lease_owner=NULL,lease_expires_at=NULL
                    WHERE workspace_id=? AND event_id=? AND lease_owner=? AND published_at IS NULL");
                $release->execute([$candidate['workspace_id'],$candidate['event_id'],$owner]);
                throw $e;
            }
            $ack=$this->db->prepare("UPDATE mos_outbox SET published_at=UTC_TIMESTAMP(6),
                lease_owner=NULL,lease_expires_at=NULL
                WHERE workspace_id=? AND event_id=? AND lease_owner=? AND published_at IS NULL");
            $ack->execute([$candidate['workspace_id'],$candidate['event_id'],$owner]);
            if($ack->rowCount()!==1)throw new RuntimeException('Publisher lost lease before confirming; delivery may have happened.');
            return (string)$candidate['event_id'];
        }
        return null;
    }
}
