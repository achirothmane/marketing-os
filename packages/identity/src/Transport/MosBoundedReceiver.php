<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Transport;

use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Contracts\Id\EventId;
use MarketingOS\Identity\Persistence\PdoInboxConsumer;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * Exactly one broker-delivery attempt per call. Queue name is tenant-scoped.
 * Poison handling only for valid pointer messages with known domain event.
 */
final readonly class MosBoundedReceiver {
    public function __construct(
        private ReceiverInterface $transport,
        private PdoInboxConsumer $inbox,
        private MosFailureLedger $failures,
        private WorkspaceId $workspace,
        private int $maxAttempts=3
    ){
        if($maxAttempts<1||$maxAttempts>25)throw new \InvalidArgumentException('Unsafe retry threshold.');
    }
    /** @return string IDLE|PROCESSED|REPLAY|RETRY|QUARANTINED */
    public function runOnce():string {
        foreach($this->transport->get() as $envelope){
            $pointer=$envelope->getMessage();
            if(!$pointer instanceof MosEventPointer || $pointer->workspaceId!==(string)$this->workspace) {
                // Never ack a pointer destined for another workspace.
                throw new \RuntimeException('WRONG_WORKSPACE_OR_TYPE');
            }
            $event=EventId::fromString($pointer->eventId);
            if($this->failures->quarantined($this->workspace,$event,'mos_identity_projection')){
                $this->transport->ack($envelope);
                return 'QUARANTINED';
            }
            try {
                $changed=$this->inbox->consume($this->workspace,$event,'mos_identity_projection',MosIdentityProjection::apply(...));
            }catch(\Throwable $error){
                $quarantine=$this->failures->recordFailure($this->workspace,$event,'mos_identity_projection',$this->maxAttempts);
                if($quarantine){
                    // Quarantine committed BEFORE broker ACK. A crash after this
                    // point is replay-safe thanks to quarantined() check.
                    $this->transport->ack($envelope);
                    return 'QUARANTINED';
                }
                return 'RETRY'; // keep broker unacknowledged for expiry
            }
            $this->transport->ack($envelope);
            return $changed?'PROCESSED':'REPLAY';
        }
        return 'IDLE';
    }
}
