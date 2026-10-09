<?php
declare(strict_types=1);
namespace MarketingOS\Contracts\Id;
use InvalidArgumentException;
/** RFC 9562 UUIDv7; strict monotonicity is not guaranteed. */
final readonly class UuidV7 {
    private function __construct(public string $value) {}
    public static function fromString(string $value): self {
        $value=strtolower($value);
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$value)) throw new InvalidArgumentException('Expected UUIDv7');
        return new self($value);
    }
    public static function generate(): self {
        $ms=(int)floor(microtime(true)*1000);
        if($ms<0||$ms>0xffffffffffff) throw new \RuntimeException('Clock outside UUIDv7 range');
        $r=bin2hex(random_bytes(10));
        $variant=dechex((hexdec($r[3])&0x3)|0x8);
        $hex=sprintf('%012x',$ms).'7'.substr($r,0,3).$variant.substr($r,4,15);
        return self::fromString(substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12));
    }
    public function equals(self $other): bool { return $this->value===$other->value; }
    public function __toString(): string { return $this->value; }
}
