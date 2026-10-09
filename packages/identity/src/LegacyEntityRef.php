<?php
declare(strict_types=1);
namespace MarketingOS\Identity;
use InvalidArgumentException;
use MarketingOS\Contracts\Id\WorkspaceId;
/** Source-controlled ID, never email or a person's display name. */
final readonly class LegacyEntityRef {
    public function __construct(
        public WorkspaceId $workspaceId,
        public string $system,
        public string $entityType,
        public string $externalId
    ) {
        if(!preg_match('/^[a-z][a-z0-9_.-]*$/D',$system)||
           !preg_match('/^[a-z][a-z0-9_.-]*$/D',$entityType))
            throw new InvalidArgumentException('System/entity type must be stable lowercase keys.');
        if($externalId===''||trim($externalId)!==$externalId||strlen($externalId)>255)
            throw new InvalidArgumentException('External ID must be trimmed non-empty <=255 bytes.');
    }
    public static function mauticContact(WorkspaceId $workspaceId,int $id): self {
        if($id<1)throw new InvalidArgumentException('Contact ID must be positive.');
        return new self($workspaceId,'mautic','contact',(string)$id);
    }
    public function key(): string {
        return hash('sha256',json_encode([(string)$this->workspaceId,$this->system,$this->entityType,$this->externalId],JSON_THROW_ON_ERROR));
    }
    public function equals(self $other): bool { return $this->key()===$other->key(); }
}
