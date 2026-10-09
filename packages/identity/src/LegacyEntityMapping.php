<?php
declare(strict_types=1);
namespace MarketingOS\Identity;
use InvalidArgumentException;
use MarketingOS\Contracts\Id\EvidenceId;
use MarketingOS\Contracts\Id\PersonId;
/** Immutable mapping claim; the DB MUST enforce unique scoped legacy key. */
final readonly class LegacyEntityMapping {
    public function __construct(
        public LegacyEntityRef $source,
        public PersonId $personId,
        public EvidenceId $evidenceId,
        public \DateTimeImmutable $recordedAt,
        public string $origin='LEGACY_IMPORT'
    ) {
        if(trim($origin)==='')throw new InvalidArgumentException('Origin required.');
        if($recordedAt->getOffset()!==0)throw new InvalidArgumentException('recordedAt must be UTC.');
    }
    public static function forPerson(LegacyEntityRef $source,Person $person,EvidenceId $evidenceId,\DateTimeImmutable $recordedAt): self {
        if(!$person->workspaceId->equals($source->workspaceId))
            throw new InvalidArgumentException('Cross-workspace binding forbidden.');
        return new self($source,$person->id,$evidenceId,$recordedAt);
    }
}
