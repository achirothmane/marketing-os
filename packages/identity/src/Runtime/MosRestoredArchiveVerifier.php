<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Runtime;

use MarketingOS\Contracts\Id\WorkspaceId;
use PDO;
use RuntimeException;

/**
 * Read-only, bounded cryptographic comparison of encrypted DLQ records in
 * two distinct databases. An archive-only drill, NOT full server backup proof.
 * No decrypted wire, key, Contact, email or payload in receipts.
 */
final readonly class MosRestoredArchiveVerifier {
    public function __construct(private PDO $source,private PDO $restored) {}

    /**
     * @param array<string,string> $keys Exact key ID to raw 32-byte key; held only in memory.
     * @return array{decision:string,workspace_id:string,verified:int,next_cursor:int,has_more:bool}
     */
    public function verifyPage(WorkspaceId $workspace,array $keys,int $limit=100,int $afterId=0):array {
        if($limit<1||$limit>100||$afterId<0)throw new RuntimeException('RESTORE_BOUNDS_INVALID');
        if($this->source->inTransaction()||$this->restored->inTransaction()) {
            throw new RuntimeException('RESTORE_TRANSACTION_UNSAFE');
        }
        if($keys===[])throw new RuntimeException('RESTORE_KEYS_MISSING');
        foreach($keys as $id=>$secret) {
            if(!is_string($id)||!preg_match('/^[A-Za-z0-9_.-]{1,32}$/D',$id)||
                !is_string($secret)||strlen($secret)!==32) {
                throw new RuntimeException('RESTORE_KEYS_INVALID');
            }
        }
        $sourceDb=(string)$this->source->query('SELECT DATABASE()')->fetchColumn();
        $restoredDb=(string)$this->restored->query('SELECT DATABASE()')->fetchColumn();
        if($sourceDb===''||$restoredDb===''||$sourceDb===$restoredDb) {
            throw new RuntimeException('RESTORE_NOT_ISOLATED');
        }
        $rowsA=$this->readPage($this->source,$workspace,$afterId,$limit+1);
        $rowsB=$this->readPage($this->restored,$workspace,$afterId,$limit+1);
        $moreA=count($rowsA)>$limit;
        $moreB=count($rowsB)>$limit;
        if($moreA!==$moreB||count($rowsA)!==count($rowsB)) {
            throw new RuntimeException('RESTORE_DIVERGED');
        }
        if($moreA){array_pop($rowsA);array_pop($rowsB);}
        $last=$afterId;$count=0;
        foreach($rowsA as $index=>$left){
            $right=$rowsB[$index];
            // Binary exactness matters: metadata and encrypted bytes all must match.
            foreach(array_keys($left) as $column) {
                if(!array_key_exists($column,$right)||
                    (string)$left[$column] !== (string)$right[$column]) {
                    throw new RuntimeException('RESTORE_DIVERGED');
                }
            }
            $keyId=(string)$left['key_id'];
            if(!array_key_exists($keyId,$keys))throw new RuntimeException('RESTORE_KEYS_MISSING');
            $this->authenticate($workspace,$left,$keys[$keyId]);
            $this->authenticate($workspace,$right,$keys[$keyId]);
            $last=(int)$left['source_message_id'];$count++;
        }
        return [
            'decision'=>$count===0 && !$moreA && $afterId===0?'EMPTY_ARCHIVE_NOT_BACKUP_PROOF':'PAGE_AUTHENTICATED',
            'workspace_id'=>(string)$workspace,
            'verified'=>$count,
            'next_cursor'=>$last,
            'has_more'=>$moreA
        ];
    }

    private function readPage(PDO $pdo,WorkspaceId $workspace,int $cursor,int $max):array {
        $q=$pdo->prepare('SELECT source_message_id,queue_name,key_id,reason_code,
          payload_sha256,sealed_nonce,sealed_payload,byte_count,quarantined_at
          FROM mos_messenger_wire_quarantine
          WHERE workspace_id=? AND source_message_id>?
          ORDER BY source_message_id LIMIT '.$max);
        $q->execute([(string)$workspace,$cursor]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    private function authenticate(WorkspaceId $workspace,array $r,string $key):void {
        $queue='mos_identity_'.(string)$workspace;
        if((string)$r['queue_name']!==$queue)throw new RuntimeException('RESTORE_SOURCE_MISMATCH');
        $nonce=(string)$r['sealed_nonce'];
        $sealed=(string)$r['sealed_payload'];
        $hash=(string)$r['payload_sha256'];
        if(strlen($nonce)!==SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES ||
            !preg_match('/^[0-9a-f]{64}$/D',$hash)||
            !hash_equals($hash,hash('sha256',$sealed))) {
            throw new RuntimeException('RESTORE_AUTH_FAILED');
        }
        $wire=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $sealed,(string)$workspace.'|'.$queue.'|'.(string)$r['source_message_id'],$nonce,$key);
        if(!is_string($wire)||strlen($wire)!==(int)$r['byte_count']||strlen($wire)<4) {
            throw new RuntimeException('RESTORE_AUTH_FAILED');
        }
        $headerSize=unpack('N',substr($wire,0,4))[1];
        if($headerSize>strlen($wire)-4)throw new RuntimeException('RESTORE_AUTH_FAILED');
    }
}
