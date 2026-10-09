<?php
declare(strict_types=1);
namespace MarketingOS\Identity;
use LogicException;
use MarketingOS\Contracts\Id\PersonId;
/** Read-side plan; NOT evidence of transactionally persisted binding. */
final readonly class MappingDecision {
    public function __construct(public MappingResolution $resolution,public ?PersonId $existingPersonId) {
        if(($resolution===MappingResolution::REUSE_EXISTING)!==($existingPersonId!==null))
            throw new LogicException('REUSE_EXISTING requires Person ID.');
    }
}
