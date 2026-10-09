<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Transport;
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Contracts\Id\EventId;
final readonly class MosEventPointer {
    public function __construct(public string $workspaceId,public string $eventId) {
        WorkspaceId::fromString($workspaceId);
        EventId::fromString($eventId);
    }
}
