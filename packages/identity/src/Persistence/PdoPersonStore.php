<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Persistence;
use DomainException;
use PDO;
use MarketingOS\Contracts\Id\PersonId;
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Person;
use MarketingOS\Identity\PersonState;
final readonly class PdoPersonStore {
    public function __construct(private PDO $db) {}
    public function createWorkspace(WorkspaceId $id): void {
        $s=$this->db->prepare("INSERT INTO mos_workspace (id,created_at) VALUES (?,UTC_TIMESTAMP(6))");
        $s->execute([(string)$id]);
    }
    public function find(WorkspaceId $workspace,PersonId $id): ?Person {
        $s=$this->db->prepare("SELECT id,workspace_id,state,version,merged_into_id FROM mos_person WHERE workspace_id=? AND id=?");
        $s->execute([(string)$workspace,(string)$id]);
        $row=$s->fetch(PDO::FETCH_ASSOC);
        if($row===false)return null;
        return Person::restore(
            WorkspaceId::fromString($row['workspace_id']),
            PersonId::fromString($row['id']),
            PersonState::from($row['state']),
            (int)$row['version'],
            $row['merged_into_id']===null?null:PersonId::fromString($row['merged_into_id'])
        );
    }
    /** Atomic, optimistic CAS; caller supplied expected version must be previous durable version. */
    public function saveTransition(Person $person,int $expectedVersion): void {
        if($person->version()!==$expectedVersion+1)throw new DomainException('Transition must advance version once.');
        $s=$this->db->prepare("UPDATE mos_person SET state=?,version=?,merged_into_id=?,updated_at=UTC_TIMESTAMP(6)
            WHERE workspace_id=? AND id=? AND version=?");
        $s->execute([$person->state()->value,$person->version(),
            $person->mergedInto()===null?null:(string)$person->mergedInto(),
            (string)$person->workspaceId,(string)$person->id,$expectedVersion]);
        if($s->rowCount()!==1)throw new DomainException('Optimistic concurrency conflict; reload Person.');
    }
}
