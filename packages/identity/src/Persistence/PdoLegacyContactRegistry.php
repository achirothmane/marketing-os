<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Persistence;
use PDO;
use PDOException;
use RuntimeException;
use MarketingOS\Contracts\Id\EvidenceId;
use MarketingOS\Contracts\Id\PersonId;
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\LegacyEntityRef;
use MarketingOS\Identity\LegacyEntityMapping;
use MarketingOS\Identity\Person;
/**
 * Registry only. Person + mapping are atomic; Evidence/DomainEvent/Outbox
 * MUST be added in the same transaction by C4-04 before real ingestion.
 * No email or other contact fields are read by this component.
 */
final readonly class PdoLegacyContactRegistry {
    public function __construct(private PDO $db) {}
    public function find(LegacyEntityRef $source): ?LegacyEntityMapping {
        $s=$this->db->prepare("SELECT person_id,evidence_id,recorded_at,origin
          FROM mos_legacy_entity_map WHERE workspace_id=? AND source_system=? AND source_entity_type=? AND source_external_id=?");
        $s->execute([(string)$source->workspaceId,$source->system,$source->entityType,$source->externalId]);
        $row=$s->fetch(PDO::FETCH_ASSOC);
        if($row===false)return null;
        return new LegacyEntityMapping($source,PersonId::fromString($row['person_id']),
          EvidenceId::fromString($row['evidence_id']),
          new \DateTimeImmutable($row['recorded_at'],new \DateTimeZone('UTC')),$row['origin']);
    }
    public function registerMauticContact(WorkspaceId $workspace,int $contactId,EvidenceId $evidence): LegacyEntityMapping {
        $source=LegacyEntityRef::mauticContact($workspace,$contactId);
        $existing=$this->find($source);
        if($existing!==null)return $existing;
        try {
            $this->db->beginTransaction();
            $person=Person::create($workspace);
            $insertPerson=$this->db->prepare("INSERT INTO mos_person
              (workspace_id,id,state,version,merged_into_id,created_at,updated_at)
              VALUES (?,?,?,1,NULL,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
            $insertPerson->execute([(string)$workspace,(string)$person->id,$person->state()->value]);
            $insertMap=$this->db->prepare("INSERT INTO mos_legacy_entity_map
              (workspace_id,source_system,source_entity_type,source_external_id,person_id,evidence_id,origin,recorded_at)
              VALUES (?,?,?,?,?,?,'LEGACY_IMPORT',UTC_TIMESTAMP(6))");
            $insertMap->execute([(string)$workspace,$source->system,$source->entityType,$source->externalId,(string)$person->id,(string)$evidence]);
            $this->db->commit();
            $result=$this->find($source);
            if($result===null)throw new RuntimeException('Committed mapping missing.');
            return $result;
        } catch(\Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();
            if($e instanceof PDOException &&
                $e->getCode()==='23000' &&
                isset($e->errorInfo[1]) && (int)$e->errorInfo[1]===1062) {
                // Another transaction won the exact scoped source key. The losing
                // transaction rolled back its new Person and reuses the winner.
                $winner=$this->find($source);
                if($winner!==null)return $winner;
            }
            throw $e;
        }
    }
}
