<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Transport;

use PDO;
use RuntimeException;
use InvalidArgumentException;
use MarketingOS\Contracts\Id\WorkspaceId;

/**
 * Atomic archive-before-delete of malformed Symfony Doctrine Messenger wire messages.
 * Never logs plaintext. Leaves valid messages and oversized messages untouched.
 * Operator MUST retain/manage encryption keys; no automated replay.
 */
final readonly class MosEncryptedWireQuarantine {
    public function __construct(
        private PDO $db,
        private MosMessengerJsonSerializer $codec,
        private string $secretKey,
        private string $keyId='manual-v1'
    ){
        if(strlen($this->secretKey)!==SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)
            throw new InvalidArgumentException('DLQ key must be 32 raw bytes.');
        if(!preg_match('/^[a-zA-Z0-9_.-]{1,32}$/D',$this->keyId))
            throw new InvalidArgumentException('Invalid DLQ key identifier.');
    }
    /** @return array{scanned:int,valid:int,quarantined:int,oversized:int} */
    public function scan(WorkspaceId $workspace,int $limit=20):array {
        if($limit<1||$limit>100)throw new InvalidArgumentException('Scan must be bounded to 1..100 rows.');
        if($this->db->inTransaction())throw new RuntimeException('Cannot scan inside a caller transaction.');
        $queue='mos_identity_'.(string)$workspace;
        $last=0;
        $stats=['scanned'=>0,'valid'=>0,'quarantined'=>0,'oversized'=>0];
        for($i=0;$i<$limit;$i++){
            $this->db->beginTransaction();
            try{
                // Doctrine claims a row by setting delivered_at on GET. We do not
                // take any unexpired claimed message; valid messages stay untouched.
                $q=$this->db->prepare("SELECT id,body,headers FROM mos_messenger_messages
                  WHERE queue_name=? AND id>? AND available_at<=UTC_TIMESTAMP()
                  AND (delivered_at IS NULL OR delivered_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 60 SECOND))
                  ORDER BY id LIMIT 1 FOR UPDATE");
                $q->execute([$queue,$last]);
                $row=$q->fetch(PDO::FETCH_ASSOC);
                if($row===false){$this->db->commit();break;}
                $last=(int)$row['id'];
                $stats['scanned']++;
                $body=(string)$row['body'];
                $headers=(string)$row['headers'];
                $bytes=strlen($body)+strlen($headers);
                if($bytes>262144){
                    // Fail-closed; no data discarded because not safely archivable.
                    $stats['oversized']++;
                    $this->db->commit();
                    continue;
                }
                $reason=$this->reason($body,$headers,$workspace);
                if($reason===null){
                    $stats['valid']++;
                    $this->db->commit();
                    continue;
                }
                $wire=pack('N',strlen($headers)).$headers.$body;
                $nonce=random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
                $aad=(string)$workspace.'|'.$queue.'|'.$last;
                $sealed=sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                    $wire,$aad,$nonce,$this->secretKey);
                $insert=$this->db->prepare("INSERT INTO mos_messenger_wire_quarantine
                    (workspace_id,source_message_id,queue_name,key_id,reason_code,
                     payload_sha256,sealed_nonce,sealed_payload,byte_count,quarantined_at)
                    VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))");
                $insert->bindValue(1,(string)$workspace);
                $insert->bindValue(2,$last,PDO::PARAM_INT);
                $insert->bindValue(3,$queue);
                $insert->bindValue(4,$this->keyId);
                $insert->bindValue(5,$reason);
                $insert->bindValue(6,hash('sha256',$wire));
                $insert->bindValue(7,$nonce,PDO::PARAM_LOB);
                $insert->bindValue(8,$sealed,PDO::PARAM_LOB);
                $insert->bindValue(9,strlen($wire),PDO::PARAM_INT);
                $insert->execute();
                $del=$this->db->prepare('DELETE FROM mos_messenger_messages WHERE id=? AND queue_name=?');
                $del->execute([$last,$queue]);
                if($del->rowCount()!==1)throw new RuntimeException('Wire moved concurrently, aborting quarantine.');
                $this->db->commit();
                $stats['quarantined']++;
            }catch(\Throwable $e){
                if($this->db->inTransaction())$this->db->rollBack();
                throw $e;
            }
        }
        return $stats;
    }
    private function reason(string $body,string $headers,WorkspaceId $workspace):?string {
        try {
            $decoded=json_decode($headers,true,16,JSON_THROW_ON_ERROR);
            if(!is_array($decoded))return 'INVALID_WIRE_HEADERS';
            $envelope=$this->codec->decode(['body'=>$body,'headers'=>$decoded]);
            $message=$envelope->getMessage();
            if(!$message instanceof MosEventPointer)return 'INVALID_WIRE_TYPE';
            if($message->workspaceId!==(string)$workspace)return 'WRONG_WORKSPACE';
            return null;
        }catch(\Throwable $e){
            return 'INVALID_WIRE_ENVELOPE';
        }
    }
}
