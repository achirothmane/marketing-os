<?php
declare(strict_types=1);
namespace MarketingOS\Contracts\Entity;
use InvalidArgumentException;
use MarketingOS\Contracts\Id\UuidV7;
final readonly class EntityRef {
    public function __construct(public string $type,public UuidV7 $id) {
        if(!preg_match('/^[a-z][a-z0-9_.-]*$/D',$type)) throw new InvalidArgumentException('Entity type must be a lowercase stable semantic key');
    }
}
