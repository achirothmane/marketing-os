<?php
declare(strict_types=1);
namespace MarketingOS\Contracts\Actor;
use InvalidArgumentException;
final readonly class ActorRef {
    public function __construct(public ActorType $type,public string $id) {
        if(trim($id)==='') throw new InvalidArgumentException('Actor identifier is required');
    }
}
