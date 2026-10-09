<?php
declare(strict_types=1);
namespace MarketingOS\Identity;
use InvalidArgumentException;
/** No email matching. C4-03 must recheck DB UNIQUE constraint under concurrency. */
final class LegacyMappingPlanner {
    public function plan(LegacyEntityRef $requested,?LegacyEntityMapping $stored): MappingDecision {
        if($stored===null)return new MappingDecision(MappingResolution::UNBOUND,null);
        if(!$requested->equals($stored->source))
            throw new InvalidArgumentException('Mapping belongs to a different source key.');
        return new MappingDecision(MappingResolution::REUSE_EXISTING,$stored->personId);
    }
}
