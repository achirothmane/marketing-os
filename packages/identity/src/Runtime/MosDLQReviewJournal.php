<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Runtime;

use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Transport\MosWireQuarantineKeyManager;
use PDO;
use RuntimeException;

/**
 * C4-07B3B3B3A: authenticated, dual-signed human decisions about encrypted
 * quarantine evidence. This ledger NEVER authorizes replay, delete, or send.
 * The rolling head MUST be pinned in an independently protected witness:
 * without that anchor an attacker with filesystem access can truncate history.
 */
final readonly class MosDLQReviewJournal {
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';
    private const FIELDS = [
        'review_id','workspace_id','case_id','key_id','ciphertext_sha256',
        'reason_code','decision','reviewer_id','approver_id'
    ];
    public function __construct(private PDO $readDb,private string $protectedDirectory) {}

    public static function genesis():string { return self::GENESIS; }

    /** @param array<string,mixed> $claim */
    public static function message(array $claim):string {
        self::validateClaim($claim);
        $stable=[];
        foreach(self::FIELDS as $name)$stable[$name]=$claim[$name];
        return "MOS-DLQ-REVIEW-V1\n".json_encode($stable,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string,mixed> $claim
     * @param array{reviewer_sig:string,approver_sig:string} $signatures
     * @param array{reviewers:array<string,string>,approvers:array<string,string>} $trust Public keys as base64
     * @return array{decision:string,head_hash:string,entry_count:int}
     */
    public function append(
        WorkspaceId $workspace,array $claim,array $signatures,
        array $trust,string $archiveKey,string $expectedPriorHead
    ):array {
        self::validateClaim($claim);
        if($claim['workspace_id']!==(string)$workspace)throw new RuntimeException('REVIEW_WORKSPACE_MISMATCH');
        if(!self::isDigest($expectedPriorHead))throw new RuntimeException('REVIEW_ANCHOR_INVALID');
        $this->verifySignatures($claim,$signatures,$trust);
        if(strlen($archiveKey)!==32)throw new RuntimeException('REVIEW_ARCHIVE_KEY_INVALID');
        if($this->readDb->inTransaction())throw new RuntimeException('REVIEW_TRANSACTION_ACTIVE');
        $this->readDb->beginTransaction();
        try {
            $q=$this->readDb->prepare('SELECT key_id,reason_code,payload_sha256
              FROM mos_messenger_wire_quarantine
              WHERE workspace_id=? AND source_message_id=?');
            $q->execute([(string)$workspace,$claim['case_id']]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if($row===false||$row['key_id']!==$claim['key_id']||
               $row['reason_code']!==$claim['reason_code']||
               !hash_equals((string)$row['payload_sha256'],$claim['ciphertext_sha256'])) {
                throw new RuntimeException('REVIEW_EVIDENCE_MISMATCH');
            }
            // Authenticate the exact encrypted record, not merely its SQL digest.
            $verified=(new MosWireQuarantineKeyManager($this->readDb))->verify(
                $workspace,$claim['key_id'],$archiveKey,1,$claim['case_id']-1
            );
            if($verified['verified']!==1||$verified['next_cursor']!==$claim['case_id']) {
                throw new RuntimeException('REVIEW_EVIDENCE_MISMATCH');
            }
            $this->readDb->commit();
        }catch(\Throwable $e){
            if($this->readDb->inTransaction())$this->readDb->rollBack();
            throw $e;
        }

        $handle=$this->openJournal(true);
        try {
            if(!flock($handle,LOCK_EX))throw new RuntimeException('REVIEW_LOCK_REFUSED');
            $history=$this->scan($handle,$trust);
            if(!hash_equals($expectedPriorHead,$history['head_hash'])) {
                throw new RuntimeException('REVIEW_ANCHOR_MISMATCH');
            }
            if(isset($history['ids'][$claim['review_id']]))throw new RuntimeException('REVIEW_DUPLICATE');
            if($history['entry_count']>=1000)throw new RuntimeException('REVIEW_JOURNAL_LIMIT');
            $prev=$history['head_hash'];
            $entry=[
                'previous_hash'=>$prev,
                'claim'=>$claim,
                'signatures'=>$signatures,
                'entry_hash'=>self::entryHash($prev,$claim,$signatures)
            ];
            $line=json_encode($entry,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
            if(strlen($line)>4096||fseek($handle,0,SEEK_END)!==0) {
                throw new RuntimeException('REVIEW_JOURNAL_WRITE_REFUSED');
            }
            if(fwrite($handle,$line)!==strlen($line)||!fflush($handle)||
               (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('REVIEW_JOURNAL_WRITE_REFUSED');
            }
            return [
                'decision'=>'RECORDED_NO_EFFECT',
                'head_hash'=>$entry['entry_hash'],
                'entry_count'=>$history['entry_count']+1
            ];
        }finally {
            flock($handle,LOCK_UN);fclose($handle);
        }
    }

    /**
     * @param array{reviewers:array<string,string>,approvers:array<string,string>} $trust
     * @return array{decision:string,head_hash:string,entry_count:int}
     */
    public function inspect(array $trust,string $expectedHead):array {
        if(!self::isDigest($expectedHead))throw new RuntimeException('REVIEW_ANCHOR_INVALID');
        $handle=$this->openJournal(false);
        if($handle===null) {
            if(!hash_equals($expectedHead,self::GENESIS))throw new RuntimeException('REVIEW_ANCHOR_MISMATCH');
            return ['decision'=>'EMPTY_JOURNAL','head_hash'=>self::GENESIS,'entry_count'=>0];
        }
        try{
            if(!flock($handle,LOCK_SH))throw new RuntimeException('REVIEW_LOCK_REFUSED');
            $h=$this->scan($handle,$trust);
            if(!hash_equals($expectedHead,$h['head_hash']))throw new RuntimeException('REVIEW_ANCHOR_MISMATCH');
            return ['decision'=>'AUTHENTICATED_JOURNAL','head_hash'=>$h['head_hash'],
                'entry_count'=>$h['entry_count']];
        }finally{flock($handle,LOCK_UN);fclose($handle);}
    }

    /** @return resource|null */
    private function openJournal(bool $create) {
        $dir=$this->protectedDirectory;
        $real=realpath($dir);
        if($real===false||$real!==$dir||!is_dir($dir)||is_link($dir)||
           (fileperms($dir)&0077)!==0)throw new RuntimeException('REVIEW_STORAGE_UNSAFE');
        $path=$dir.'/dlq-review-v1.jsonl';
        if(is_link($path))throw new RuntimeException('REVIEW_STORAGE_UNSAFE');
        if(!file_exists($path)) {
            if(!$create)return null;
            $old=umask(0077);
            try{$h=@fopen($path,'x+b');}
            finally{umask($old);}
            if($h!==false)return $h;
        }
        if(!is_file($path)||(fileperms($path)&0077)!==0) {
            throw new RuntimeException('REVIEW_STORAGE_UNSAFE');
        }
        $h=@fopen($path,$create?'c+b':'rb');
        if($h===false)throw new RuntimeException('REVIEW_STORAGE_UNSAFE');
        return $h;
    }

    /** @param resource $handle */
    private function scan($handle,array $trust):array {
        rewind($handle);$head=self::GENESIS;$ids=[];$count=0;
        while(($line=fgets($handle,4097))!==false) {
            if(strlen($line)>4096||!str_ends_with($line,"\n")) {
                throw new RuntimeException('REVIEW_JOURNAL_CORRUPT');
            }
            try{$entry=json_decode($line,true,20,JSON_THROW_ON_ERROR);}
            catch(\Throwable $e){throw new RuntimeException('REVIEW_JOURNAL_CORRUPT');}
            if(!is_array($entry)||array_keys($entry)!==['previous_hash','claim','signatures','entry_hash']||
               !is_array($entry['claim'])||!is_array($entry['signatures'])||
               !is_string($entry['previous_hash'])||!is_string($entry['entry_hash'])||
               !self::isDigest($entry['entry_hash'])||
               !hash_equals($head,$entry['previous_hash'])) {
                throw new RuntimeException('REVIEW_JOURNAL_CORRUPT');
            }
            try {
                $this->verifySignatures($entry['claim'],$entry['signatures'],$trust);
                $computed=self::entryHash($head,$entry['claim'],$entry['signatures']);
            } catch(\Throwable $e) {throw new RuntimeException('REVIEW_JOURNAL_CORRUPT');}
            if(!hash_equals($computed,$entry['entry_hash'])||
               isset($ids[$entry['claim']['review_id']])) {
                throw new RuntimeException('REVIEW_JOURNAL_CORRUPT');
            }
            $head=$computed;$ids[$entry['claim']['review_id']]=true;$count++;
            if($count>1000)throw new RuntimeException('REVIEW_JOURNAL_LIMIT');
        }
        return ['head_hash'=>$head,'entry_count'=>$count,'ids'=>$ids];
    }

    private static function entryHash(string $prev,array $claim,array $signatures):string {
        return hash('sha256',$prev."\n".self::message($claim)."\n".
            $signatures['reviewer_sig']."\n".$signatures['approver_sig']);
    }

    private function verifySignatures(array $claim,array $signatures,array $trust):void {
        self::validateClaim($claim);
        if(array_keys($signatures)!==['reviewer_sig','approver_sig']||
           $claim['reviewer_id']===$claim['approver_id']||
           !isset($trust['reviewers'][$claim['reviewer_id']])||
           !isset($trust['approvers'][$claim['approver_id']])) {
            throw new RuntimeException('REVIEW_AUTHORITY_REFUSED');
        }
        $reviewer=self::decoded($trust['reviewers'][$claim['reviewer_id']],32);
        $approver=self::decoded($trust['approvers'][$claim['approver_id']],32);
        if(hash_equals($reviewer,$approver))throw new RuntimeException('REVIEW_AUTHORITY_REFUSED');
        $message=self::message($claim);
        $sigReview=self::decoded($signatures['reviewer_sig'],64);
        $sigApprove=self::decoded($signatures['approver_sig'],64);
        if(!sodium_crypto_sign_verify_detached($sigReview,$message,$reviewer)||
           !sodium_crypto_sign_verify_detached($sigApprove,$message,$approver)) {
            throw new RuntimeException('REVIEW_SIGNATURE_INVALID');
        }
    }
    private static function decoded(mixed $value,int $length):string {
        $raw=is_string($value)?base64_decode($value,true):false;
        if(!is_string($raw)||strlen($raw)!==$length) {
            throw new RuntimeException('REVIEW_SIGNATURE_INVALID');
        }
        return $raw;
    }
    private static function isDigest(string $d):bool {return (bool)preg_match('/^[a-f0-9]{64}$/D',$d);}
    private static function validateClaim(array $claim):void {
        $actual=array_keys($claim);sort($actual);
        $expected=self::FIELDS;sort($expected);
        if($actual!==$expected||!is_string($claim['review_id'])||
           !preg_match('/^[a-f0-9]{32}$/D',$claim['review_id'])||
           !is_string($claim['workspace_id'])||
           !preg_match('/^[a-f0-9-]{36}$/D',$claim['workspace_id'])||
           !is_int($claim['case_id'])||$claim['case_id']<1||
           !is_string($claim['key_id'])||
           !preg_match('/^[A-Za-z0-9_.-]{1,32}$/D',$claim['key_id'])||
           !is_string($claim['ciphertext_sha256'])||
           !self::isDigest($claim['ciphertext_sha256'])||
           !is_string($claim['reason_code'])||
           !preg_match('/^[A-Z0-9_]{3,48}$/D',$claim['reason_code'])||
           !in_array($claim['decision'],['CONTINUE_HOLD','ESCALATE','REJECT_REPLAY'],true)||
           !is_string($claim['reviewer_id'])||
           !preg_match('/^[A-Za-z0-9_.-]{2,40}$/D',$claim['reviewer_id'])||
           !is_string($claim['approver_id'])||
           !preg_match('/^[A-Za-z0-9_.-]{2,40}$/D',$claim['approver_id'])) {
            throw new RuntimeException('REVIEW_CLAIM_INVALID');
        }
    }
}
