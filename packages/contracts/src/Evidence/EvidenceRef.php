<?php
declare(strict_types=1);
namespace MarketingOS\Contracts\Evidence;
use InvalidArgumentException;
use MarketingOS\Contracts\Id\EvidenceId;
final readonly class EvidenceRef {
    public function __construct(public EvidenceId $id,public string $kind,public string $sourceSystem,public string $sourceLocator,public string $sha256,public \DateTimeImmutable $observedAt) {
        if(trim($kind)===''||trim($sourceSystem)===''||trim($sourceLocator)==='') throw new InvalidArgumentException('Evidence kind/source/locator required');
        if(!preg_match('/^[0-9a-f]{64}$/D',$sha256)) throw new InvalidArgumentException('Evidence SHA-256 must be lower-case 64 hex');
    }
}
