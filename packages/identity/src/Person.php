<?php
declare(strict_types=1);
namespace MarketingOS\Identity;
use DomainException;
use MarketingOS\Contracts\Id\PersonId;
use MarketingOS\Contracts\Id\WorkspaceId;
/** Canonical lifecycle only; email is never a Person identity key. */
final class Person {
    private function __construct(
        public readonly PersonId $id,
        public readonly WorkspaceId $workspaceId,
        private PersonState $state,
        private int $version,
        private ?PersonId $mergedInto
    ) {}
    public static function create(WorkspaceId $workspaceId, ?PersonId $id=null): self {
        return new self($id??PersonId::generate(),$workspaceId,PersonState::ACTIVE,1,null);
    }
    public function state(): PersonState { return $this->state; }
    public function version(): int { return $this->version; }
    public function mergedInto(): ?PersonId { return $this->mergedInto; }
    public function mergeInto(self $target,int $expectedVersion): void {
        $this->requireVersion($expectedVersion);
        if($this->state!==PersonState::ACTIVE||$target->state!==PersonState::ACTIVE)
            throw new DomainException('Only ACTIVE persons can merge.');
        if($this->id->equals($target->id))throw new DomainException('Self-merge forbidden.');
        if(!$this->workspaceId->equals($target->workspaceId))
            throw new DomainException('Cross-workspace merge forbidden.');
        $this->state=PersonState::MERGED;
        $this->mergedInto=$target->id;
        $this->version++;
    }
    /** Only a lifecycle marker; this does NOT erase actual stored PII. */
    public function markErased(int $expectedVersion): void {
        $this->requireVersion($expectedVersion);
        if($this->state===PersonState::ERASED)throw new DomainException('Already erased.');
        $this->state=PersonState::ERASED;
        $this->version++;
    }
    private function requireVersion(int $expected): void {
        if($expected!==$this->version)throw new DomainException('Stale Person version.');
    }
}
