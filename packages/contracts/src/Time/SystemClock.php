<?php
declare(strict_types=1);
namespace MarketingOS\Contracts\Time;
final class SystemClock implements Clock {
    public function now(): \DateTimeImmutable { return new \DateTimeImmutable('now',new \DateTimeZone('UTC')); }
}
