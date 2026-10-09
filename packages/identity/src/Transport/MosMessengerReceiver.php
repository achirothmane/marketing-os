<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Transport;
use MarketingOS\Identity\Persistence\PdoInboxConsumer;
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Contracts\Id\EventId;
use PDO;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
/** Ack transport after the local Inbox + projection transaction commits. */
final readonly class MosMessengerReceiver {
    public function __construct(private ReceiverInterface $receiver,private PdoInboxConsumer $inbox){}
    /** @param callable(array,PDO):void $projection */
    public function consumeOne(callable $projection,string $consumerKey='mos_identity_projection'):bool {
        foreach($this->receiver->get() as $envelope){
            $msg=$envelope->getMessage();
            if(!$msg instanceof MosEventPointer)throw new \RuntimeException('Unexpected message type.');
            $this->inbox->consume(
                WorkspaceId::fromString($msg->workspaceId),
                EventId::fromString($msg->eventId),
                $consumerKey,$projection
            );
            $this->receiver->ack($envelope);
            return true;
        }
        return false;
    }
}
