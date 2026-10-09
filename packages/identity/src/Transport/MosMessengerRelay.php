<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Transport;
use MarketingOS\Identity\Persistence\PdoOutboxPublisher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
/** A published pointer means durable transport accepted it, not the consumer processed it. */
final readonly class MosMessengerRelay {
    public function __construct(private PdoOutboxPublisher $outbox,private SenderInterface $sender){}
    public function publishOne():?string {
        return $this->outbox->publishOne(function(array $event):void {
            $this->sender->send(new Envelope(new MosEventPointer($event['workspace_id'],$event['event_id'])));
        });
    }
}
