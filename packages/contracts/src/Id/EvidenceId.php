<?php
declare(strict_types=1);
namespace MarketingOS\Contracts\Id;
final readonly class EvidenceId {
    public function __construct(public UuidV7 $uuid) {}
    public static function generate(): self { return new self(UuidV7::generate()); }
    public static function fromString(string $s): self { return new self(UuidV7::fromString($s)); }
    public function equals(self $other): bool { return $this->uuid->equals($other->uuid); }
    public function __toString(): string { return (string) $this->uuid; }
}
