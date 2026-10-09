<?php
declare(strict_types=1);
namespace MarketingOS\Identity;
use MarketingOS\Contracts\Id\EventId;
final readonly class ImportResult {
    public function __construct(
        public LegacyEntityMapping $mapping,
        public ?EventId $eventId,
        public bool $created
    ) {}
}
