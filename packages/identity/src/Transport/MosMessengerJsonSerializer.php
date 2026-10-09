<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Transport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Throwable;
/** Strict JSON allowlist, not PHP unserialize. No raw Contact PII. */
final class MosMessengerJsonSerializer implements SerializerInterface {
    public function encode(Envelope $envelope):array {
        $msg=$envelope->getMessage();
        if(!$msg instanceof MosEventPointer)throw new \InvalidArgumentException('Only MOS pointers permitted.');
        return ['body'=>json_encode(['schema'=>1,'workspace_id'=>$msg->workspaceId,'event_id'=>$msg->eventId],JSON_THROW_ON_ERROR),
                'headers'=>['type'=>'mos.event_pointer.v1','Content-Type'=>'application/json']];
    }
    public function decode(array $encodedEnvelope):Envelope {
        try {
            if(($encodedEnvelope['headers']['type']??null)!=='mos.event_pointer.v1')throw new \InvalidArgumentException('Unknown message type.');
            $data=json_decode($encodedEnvelope['body']??'',true,8,JSON_THROW_ON_ERROR);
            if(!is_array($data)||array_keys($data)!==['schema','workspace_id','event_id']||$data['schema']!==1
                ||!is_string($data['workspace_id'])||!is_string($data['event_id']))
                throw new \InvalidArgumentException('Invalid pointer envelope.');
            return new Envelope(new MosEventPointer($data['workspace_id'],$data['event_id']));
        }catch(Throwable $e){
            throw new MessageDecodingFailedException('Invalid MOS message envelope',0,$e);
        }
    }
}
