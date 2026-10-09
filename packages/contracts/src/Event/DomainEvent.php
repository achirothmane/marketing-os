<?php
declare(strict_types=1);
namespace MarketingOS\Contracts\Event;
use InvalidArgumentException;
use MarketingOS\Contracts\Actor\ActorRef;
use MarketingOS\Contracts\Entity\EntityRef;
use MarketingOS\Contracts\Id\EventId;
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Contracts\Id\UuidV7;
final readonly class DomainEvent {
    public function __construct(
        public EventId $eventId,
        public string $eventType,
        public int $schemaVersion,
        public WorkspaceId $workspaceId,
        public EntityRef $aggregate,
        public int $aggregateVersion,
        public \DateTimeImmutable $occurredAt,
        public \DateTimeImmutable $recordedAt,
        public ActorRef $actor,
        public UuidV7 $correlationId,
        public ?EventId $causationId,
        public array $payload,
        public array $metadata=[]
    ){
        if(!preg_match('/^[a-z][a-z0-9_-]*(\.[a-z][a-z0-9_-]*)+\.v[1-9][0-9]*$/D',$eventType)) throw new InvalidArgumentException('Invalid versioned event type');
        if($schemaVersion<1||$aggregateVersion<1) throw new InvalidArgumentException('Versions must be positive');
        if(!str_ends_with($eventType,'.v'.$schemaVersion)) throw new InvalidArgumentException('Event schema/type mismatch');
    }
}
