<?php
declare(strict_types=1);
namespace MarketingOS\Contracts\Money;
use InvalidArgumentException;
/** Decimal string, no floating point, no arithmetic until precision/rounding contract. */
final readonly class Money {
    public function __construct(public string $amount,public string $currency) {
        if(!preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,8})?$/D',$amount)) throw new InvalidArgumentException('Expected decimal string (<=8 fractional places)');
        if(!preg_match('/^[A-Z]{3}$/D',$currency)) throw new InvalidArgumentException('Expected uppercase 3-letter currency');
    }
    public function assertSameCurrency(self $other): void {
        if($this->currency!==$other->currency) throw new InvalidArgumentException('Currency mismatch; evidenced FX required');
    }
}
