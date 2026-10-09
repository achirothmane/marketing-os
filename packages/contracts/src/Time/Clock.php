<?php
declare(strict_types=1);
namespace MarketingOS\Contracts\Time;
interface Clock { public function now(): \DateTimeImmutable; }
