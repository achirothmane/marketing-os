<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Bridge;
use PDO;
use InvalidArgumentException;
use RuntimeException;
use MarketingOS\Contracts\Id\WorkspaceId;
final readonly class PdoMauticContactSnapshotReader {
    private string $table;
    public function __construct(private PDO $db,private string $key,string $tablePrefix=''){
        if(strlen($key)<32)throw new InvalidArgumentException('HMAC key must be >=32 bytes.');
        if(!preg_match('/^[A-Za-z0-9_]{0,32}$/D',$tablePrefix))throw new InvalidArgumentException('Invalid table prefix.');
        $this->table=$tablePrefix.'leads';
    }
    /** Bounded, ID-only committed-source discovery; no PII in scan results. */
    public function contactIdsAfter(int $after,int $limit):array {
        if($after<0 || $limit<1 || $limit>1000)throw new InvalidArgumentException('Invalid scan window.');
        $stmt=$this->db->prepare('SELECT id FROM '.$this->table.' WHERE id>? ORDER BY id ASC LIMIT '.$limit);
        $stmt->execute([$after]);
        return array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    public function fingerprint(WorkspaceId $workspace,int $id):string {
        if($id<1)throw new InvalidArgumentException('Contact ID must be positive.');
        $s=$this->db->prepare('SELECT id,email,firstname,lastname,date_added,date_modified FROM '.$this->table.' WHERE id=?');
        $s->execute([$id]);
        $row=$s->fetch(PDO::FETCH_ASSOC);
        if($row===false)throw new RuntimeException('CONTACT_NOT_FOUND_OR_NOT_PERSISTED');
        $payload=json_encode([
          'schema'=>'mautic-lead-v1','workspace'=>(string)$workspace,'id'=>(string)$row['id'],
          'email'=>$row['email'],'firstname'=>$row['firstname'],'lastname'=>$row['lastname'],
          'date_added'=>$row['date_added'],'date_modified'=>$row['date_modified']
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        return hash_hmac('sha256',$payload,$this->key);
    }
}
