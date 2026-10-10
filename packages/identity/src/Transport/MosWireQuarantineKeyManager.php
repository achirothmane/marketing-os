<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Transport;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use MarketingOS\Contracts\Id\WorkspaceId;

/**
 * Bounded, workspace-scoped inspection and online re-encryption of archived wire.
 * Archives NEVER return plaintext or automatic replay instructions to callers.
 *
 * A malformed wire may contain PII; the only output is non-sensitive counters.
 * Rotation uses a separate InnoDB transaction for each row. Previously committed
 * rotations survive process death and remaining old-key rows are safely resumable.
 */
final readonly class MosWireQuarantineKeyManager {
    public function __construct(private PDO $db) {}

    /** @return array{verified:int,next_cursor:int,has_more:bool} */
    public function verify(
        WorkspaceId $workspace,string $keyId,string $key,int $limit=100,int $afterId=0
    ):array {
        self::assertKey($keyId,$key);
        self::assertLimit($limit);
        if($afterId<0)throw new InvalidArgumentException('Invalid cursor.');
        $q=$this->db->prepare('SELECT source_message_id,queue_name,sealed_nonce,
            sealed_payload,payload_sha256,byte_count
            FROM mos_messenger_wire_quarantine
            WHERE workspace_id=? AND key_id=? AND source_message_id>?
            ORDER BY source_message_id LIMIT '.($limit+1));
        $q->execute([(string)$workspace,$keyId,$afterId]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $hasMore=count($rows)>$limit;
        if($hasMore)array_pop($rows);
        $last=$afterId;
        foreach($rows as $row){
            $this->validateArchive($workspace,$row,$key);
            $last=(int)$row['source_message_id'];
        }
        return ['verified'=>count($rows),'next_cursor'=>$last,'has_more'=>$hasMore];
    }

    /** @return array{rotated:int,remaining_old_key:int} */
    public function rotate(
        WorkspaceId $workspace,string $fromKeyId,string $oldKey,
        string $toKeyId,string $newKey,int $limit=100
    ):array {
        self::assertKey($fromKeyId,$oldKey);
        self::assertKey($toKeyId,$newKey);
        self::assertLimit($limit);
        if($fromKeyId===$toKeyId||hash_equals($oldKey,$newKey)){
            throw new InvalidArgumentException('Rotation needs distinct key ids and material.');
        }
        if($this->db->inTransaction())throw new RuntimeException('Nested DLQ rotation forbidden.');
        $last=0;$count=0;
        for($i=0;$i<$limit;$i++){
            $this->db->beginTransaction();
            try {
                $q=$this->db->prepare("SELECT source_message_id,queue_name,sealed_nonce,
                  sealed_payload,payload_sha256,byte_count
                  FROM mos_messenger_wire_quarantine
                  WHERE workspace_id=? AND key_id=? AND source_message_id>?
                  ORDER BY source_message_id LIMIT 1 FOR UPDATE");
                $q->execute([(string)$workspace,$fromKeyId,$last]);
                $row=$q->fetch(PDO::FETCH_ASSOC);
                if($row===false){$this->db->commit();break;}
                $id=(int)$row['source_message_id'];
                // Authenticate all record bytes BEFORE mutating anything.
                $wire=$this->validateArchive($workspace,$row,$oldKey);
                $nonce=random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
                $aad=(string)$workspace.'|'.$row['queue_name'].'|'.$id;
                $sealed=sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($wire,$aad,$nonce,$newKey);
                // Ensure the newly encrypted record can be authenticated before COMMIT.
                if(sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($sealed,$aad,$nonce,$newKey)!==$wire){
                    throw new RuntimeException('DLQ rotation self-check failed.');
                }
                $upd=$this->db->prepare("UPDATE mos_messenger_wire_quarantine
                  SET key_id=?,sealed_nonce=?,sealed_payload=?,payload_sha256=?
                  WHERE workspace_id=? AND source_message_id=? AND key_id=?");
                $upd->bindValue(1,$toKeyId);
                $upd->bindValue(2,$nonce,PDO::PARAM_LOB);
                $upd->bindValue(3,$sealed,PDO::PARAM_LOB);
                $upd->bindValue(4,hash('sha256',$sealed));
                $upd->bindValue(5,(string)$workspace);
                $upd->bindValue(6,$id,PDO::PARAM_INT);
                $upd->bindValue(7,$fromKeyId);
                $upd->execute();
                if($upd->rowCount()!==1)throw new RuntimeException('DLQ record changed during rotation.');
                $this->db->commit();
                $last=$id;$count++;
            }catch(\Throwable $e){
                if($this->db->inTransaction())$this->db->rollBack();
                throw $e;
            }
        }
        $q=$this->db->prepare('SELECT COUNT(*) FROM mos_messenger_wire_quarantine
          WHERE workspace_id=? AND key_id=?');
        $q->execute([(string)$workspace,$fromKeyId]);
        return ['rotated'=>$count,'remaining_old_key'=>(int)$q->fetchColumn()];
    }

    /** @return array{total:int,reason_counts:array<string,int>,key_counts:array<string,int>} */
    public function inventory(WorkspaceId $workspace):array {
        $q=$this->db->prepare("SELECT key_id,reason_code,COUNT(*) AS amount
          FROM mos_messenger_wire_quarantine WHERE workspace_id=?
          GROUP BY key_id,reason_code ORDER BY key_id,reason_code");
        $q->execute([(string)$workspace]);
        $keys=[];$reasons=[];$total=0;
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $amount=(int)$row['amount'];
            $keys[$row['key_id']]=($keys[$row['key_id']]??0)+$amount;
            $reasons[$row['reason_code']]=($reasons[$row['reason_code']]??0)+$amount;
            $total+=$amount;
        }
        return ['total'=>$total,'reason_counts'=>$reasons,'key_counts'=>$keys];
    }

    private function validateArchive(WorkspaceId $workspace,array $row,string $key):string {
        $id=(int)$row['source_message_id'];
        $queue='mos_identity_'.(string)$workspace;
        if($row['queue_name']!==$queue)throw new RuntimeException('ARCHIVE_SOURCE_MISMATCH');
        $sealed=(string)$row['sealed_payload'];
        $nonce=(string)$row['sealed_nonce'];
        if(strlen($nonce)!==SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES||
           !preg_match('/^[a-f0-9]{64}$/D',(string)$row['payload_sha256'])||
           !hash_equals((string)$row['payload_sha256'],hash('sha256',$sealed))){
            throw new RuntimeException('ARCHIVE_AUTHENTICATION_FAILED');
        }
        $wire=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $sealed,(string)$workspace.'|'.$queue.'|'.$id,$nonce,$key
        );
        if(!is_string($wire)||strlen($wire)!==(int)$row['byte_count']||strlen($wire)<4){
            throw new RuntimeException('ARCHIVE_AUTHENTICATION_FAILED');
        }
        $headerSize=unpack('N',substr($wire,0,4))[1];
        if($headerSize>strlen($wire)-4)throw new RuntimeException('ARCHIVE_AUTHENTICATION_FAILED');
        return $wire;
    }
    private static function assertKey(string $keyId,string $key):void {
        if(!preg_match('/^[a-zA-Z0-9_.-]{1,32}$/D',$keyId)||
           strlen($key)!==SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES){
            throw new InvalidArgumentException('Invalid DLQ key identifier/material.');
        }
    }
    private static function assertLimit(int $limit):void {
        if($limit<1||$limit>100)throw new InvalidArgumentException('DLQ operation limit must be 1..100.');
    }
}