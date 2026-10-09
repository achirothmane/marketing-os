<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Persistence;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use MarketingOS\Contracts\Actor\ActorRef;
use MarketingOS\Contracts\Actor\ActorType;
use MarketingOS\Contracts\Entity\EntityRef;
use MarketingOS\Contracts\Event\DomainEvent;
use MarketingOS\Contracts\Id\EvidenceId;
use MarketingOS\Contracts\Id\EventId;
use MarketingOS\Contracts\Id\UuidV7;
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\ImportResult;
use MarketingOS\Identity\LegacyEntityRef;
use MarketingOS\Identity\LegacyEntityMapping;
use MarketingOS\Identity\Person;

/**
 * Only C4-04 import route: one local transaction covering
 * Person, legacy mapping, evidence, domain event and outbox.
 *
 * Input SHA256 is a CALLER ASSERTION; C4-05 must bind it to a real
 * allowlisted Mautic Contact snapshot, not invented evidence.
 */
final readonly class PdoCanonicalContactImporter {
    public function __construct(private PDO $db) {}

    /**
     * @param ?callable(string):void $checkpoint Failure injection for tests ONLY.
     */
    public function import(
        WorkspaceId $workspace,
        int $contactId,
        string $snapshotSha256,
        ?callable $checkpoint = null
    ): ImportResult {
        if (!preg_match('/^[a-f0-9]{64}$/D', $snapshotSha256)) {
            throw new InvalidArgumentException('Evidence requires lowercase SHA256 digest.');
        }
        $source=LegacyEntityRef::mauticContact($workspace,$contactId);
        if($this->db->inTransaction())throw new RuntimeException('Nested importer transaction prohibited.');
        $registry=new PdoLegacyContactRegistry($this->db);
        try{
            $this->db->beginTransaction();
            $existing=$registry->find($source);
            if($existing!==null){
                $this->db->commit();
                return new ImportResult($existing,null,false);
            }
            $person=Person::create($workspace);
            $evidence=EvidenceId::generate();
            $eventId=EventId::generate();
            $recordedAt=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
            $occurredAt=$recordedAt;
            $sourceLocator='mautic://contact/'.$contactId;

            $s=$this->db->prepare("INSERT INTO mos_person
                (workspace_id,id,state,version,merged_into_id,created_at,updated_at)
                VALUES(?,?,?,1,NULL,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
            $s->execute([(string)$workspace,(string)$person->id,$person->state()->value]);
            $this->checkpoint($checkpoint,'after_person');

            $s=$this->db->prepare("INSERT INTO mos_evidence
                (workspace_id,id,kind,source_system,source_locator,content_sha256,observed_at,recorded_at)
                VALUES(?,?,?, ?,?,?,?,UTC_TIMESTAMP(6))");
            $s->execute([(string)$workspace,(string)$evidence,'legacy_contact_snapshot_digest',
                'mautic',$sourceLocator,$snapshotSha256,$recordedAt->format('Y-m-d H:i:s.u')]);
            $this->checkpoint($checkpoint,'after_evidence');

            $s=$this->db->prepare("INSERT INTO mos_legacy_entity_map
                (workspace_id,source_system,source_entity_type,source_external_id,person_id,evidence_id,origin,recorded_at)
                VALUES(?,?,?,?,?,?,'LEGACY_IMPORT',UTC_TIMESTAMP(6))");
            $s->execute([(string)$workspace,$source->system,$source->entityType,$source->externalId,
                (string)$person->id,(string)$evidence]);
            $this->checkpoint($checkpoint,'after_mapping');

            $event=new DomainEvent(
                $eventId,'identity.person.imported.v1',1,$workspace,
                new EntityRef('identity.person',$person->id->uuid),1,
                $occurredAt,$recordedAt,new ActorRef(ActorType::SERVICE,'mautic-import-adapter'),
                UuidV7::generate(),null,
                [
                    'legacy_system'=>$source->system,
                    'legacy_type'=>$source->entityType,
                    'legacy_id'=>$source->externalId,
                    'evidence_id'=>(string)$evidence,
                ]
            );
            $s=$this->db->prepare("INSERT INTO mos_domain_event
                (workspace_id,event_id,event_type,schema_version,aggregate_type,aggregate_id,aggregate_version,
                 evidence_id,actor_type,actor_id,correlation_id,causation_id,occurred_at,recorded_at,payload_json,metadata_json)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $s->execute([(string)$event->workspaceId,(string)$event->eventId,$event->eventType,$event->schemaVersion,
                $event->aggregate->type,(string)$event->aggregate->id,$event->aggregateVersion,
                (string)$evidence,$event->actor->type->value,$event->actor->id,
                (string)$event->correlationId,null,
                $event->occurredAt->format('Y-m-d H:i:s.u'),$event->recordedAt->format('Y-m-d H:i:s.u'),
                json_encode($event->payload,JSON_THROW_ON_ERROR),json_encode($event->metadata,JSON_THROW_ON_ERROR)]);
            $this->checkpoint($checkpoint,'after_event');

            $s=$this->db->prepare("INSERT INTO mos_outbox
                (workspace_id,event_id,created_at) VALUES(?,?,UTC_TIMESTAMP(6))");
            $s->execute([(string)$workspace,(string)$eventId]);
            $this->checkpoint($checkpoint,'after_outbox');

            $this->db->commit();
            $mapping=$registry->find($source);
            if($mapping===null)throw new RuntimeException('Committed import not visible.');
            return new ImportResult($mapping,$eventId,true);
        } catch(\Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();
            if($e instanceof PDOException && $e->getCode()==='23000' &&
                isset($e->errorInfo[1]) && (int)$e->errorInfo[1]===1062) {
                $winner=$registry->find($source);
                if($winner!==null)return new ImportResult($winner,null,false);
            }
            throw $e;
        }
    }
    private function checkpoint(?callable $callback,string $step):void {
        if($callback!==null)$callback($step);
    }
}
