<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Bridge;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use MarketingOS\Contracts\Id\WorkspaceId;

/**
 * C4-06 diagnostic ONLY. Immutable material-change observations;
 * never mutates Person, source Contact, legacy Mapping, Consent, Event or Outbox.
 *
 * Missing in one source read is an OBSERVATION, not proof of deletion.
 */
final readonly class PdoMauticSourceAuditor {
    public function __construct(
        private PDO $db,
        private PdoMauticContactSnapshotReader $reader,
        private int $missingConfirmGapSeconds=60
    ) {
        if($missingConfirmGapSeconds<1||$missingConfirmGapSeconds>86400) {
            throw new InvalidArgumentException('Missing confirmation gap must be 1..86400 seconds.');
        }
    }

    /** @return array{status:string,revision:int,missing_observations:int,changed:bool} */
    public function auditOne(WorkspaceId $workspace,int $contactId):array {
        if($contactId<=0)throw new InvalidArgumentException('Contact ID must be positive.');
        if($this->db->inTransaction())throw new RuntimeException('Nested audit transaction refused.');
        // The read is an observation from a separate committed-source perspective.
        // It is NOT a claim that this Contact remains unchanged after the read.
        $observedFingerprint=$this->reader->fingerprintOrNull($workspace,$contactId);
        $sourceId=(string)$contactId;
        $system='mautic';$type='contact';
        $this->db->beginTransaction();
        try{
            $stmt=$this->db->prepare("SELECT m.evidence_id,e.content_sha256,e.source_locator
                FROM mos_legacy_entity_map m
                LEFT JOIN mos_evidence e ON e.workspace_id=m.workspace_id AND e.id=m.evidence_id
                WHERE m.workspace_id=? AND m.source_system=? AND m.source_entity_type=?
                AND m.source_external_id=? FOR UPDATE");
            $stmt->execute([(string)$workspace,$system,$type,$sourceId]);
            $mapping=$stmt->fetch(PDO::FETCH_ASSOC);
            if($mapping===false)throw new DomainException('UNMAPPED_CONTACT');
            $previousQuery=$this->db->prepare("SELECT status,observed_fingerprint,missing_observations,
                revision,last_observed_at FROM mos_source_reconciliation_case
                WHERE workspace_id=? AND source_system=? AND source_entity_type=? AND source_external_id=? FOR UPDATE");
            $previousQuery->execute([(string)$workspace,$system,$type,$sourceId]);
            $previous=$previousQuery->fetch(PDO::FETCH_ASSOC);
            $clock=$this->db->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn();
            if(!is_string($clock))throw new RuntimeException('DB clock unavailable.');
            $now=new DateTimeImmutable($clock,new DateTimeZone('UTC'));
            $absence=0;
            if($mapping['content_sha256']===null ||
               $mapping['source_locator']!=='mautic://contact/'.$sourceId){
                $status='UNVERIFIED_LEGACY';
            }elseif($observedFingerprint===null){
                $absence=1;
                if($previous!==false && str_starts_with($previous['status'],'SOURCE_MISSING_')){
                    $last=new DateTimeImmutable($previous['last_observed_at'],new DateTimeZone('UTC'));
                    $gap=$now->getTimestamp()-$last->getTimestamp();
                    $absence=(int)$previous['missing_observations'];
                    if($gap >= $this->missingConfirmGapSeconds)$absence=min(2,$absence+1);
                }
                $status=$absence>=2?'SOURCE_MISSING_REPEATED':'SOURCE_MISSING_ONCE';
            }else{
                $status=hash_equals($mapping['content_sha256'],$observedFingerprint)
                    ?'MATCH':'SOURCE_CHANGED';
            }
            $newFingerprint=$observedFingerprint;
            // Stable observations do not create unbounded duplicated history.
            if($previous!==false && $previous['status']===$status
                && $previous['observed_fingerprint']===$newFingerprint
                && (int)$previous['missing_observations']===$absence) {
                $this->db->commit();
                return ['status'=>$status,'revision'=>(int)$previous['revision'],
                    'missing_observations'=>$absence,'changed'=>false];
            }
            $revision=$previous===false?1:((int)$previous['revision']+1);
            if($previous===false){
                $insert=$this->db->prepare("INSERT INTO mos_source_reconciliation_case
                    (workspace_id,source_system,source_entity_type,source_external_id,status,
                     observed_fingerprint,missing_observations,revision,first_observed_at,last_observed_at)
                     VALUES(?,?,?,?,?,?,?,?,?,?)");
                $insert->execute([(string)$workspace,$system,$type,$sourceId,$status,
                    $newFingerprint,$absence,$revision,$clock,$clock]);
            }else{
                $update=$this->db->prepare("UPDATE mos_source_reconciliation_case
                    SET status=?,observed_fingerprint=?,missing_observations=?,revision=?,last_observed_at=?
                    WHERE workspace_id=? AND source_system=? AND source_entity_type=? AND source_external_id=?
                    AND revision=?");
                $update->execute([$status,$newFingerprint,$absence,$revision,$clock,
                    (string)$workspace,$system,$type,$sourceId,$revision-1]);
                if($update->rowCount()!==1)throw new RuntimeException('Audit revision conflict.');
            }
            $history=$this->db->prepare("INSERT INTO mos_source_reconciliation_observation
                (workspace_id,source_system,source_entity_type,source_external_id,
                 status,observed_fingerprint,observed_at,revision)
                VALUES(?,?,?,?,?,?,?,?)");
            $history->execute([(string)$workspace,$system,$type,$sourceId,$status,
                $newFingerprint,$clock,$revision]);
            $this->db->commit();
            return ['status'=>$status,'revision'=>$revision,
                'missing_observations'=>$absence,'changed'=>true];
        }catch(\Throwable $error){
            if($this->db->inTransaction())$this->db->rollBack();
            throw $error;
        }
    }

    /**
     * Audit already mapped Contact IDs, including missing source rows.
     * A numeric mapping ID cursor is an invocation boundary, not completeness.
     * @return array{seen:int,next_after_id:int,has_more:bool,status_counts:array<string,int>}
     */
    public function auditMappedBatch(WorkspaceId $workspace,int $afterId=0,int $limit=100):array {
        if($afterId<0||$limit<1||$limit>500)throw new InvalidArgumentException('Invalid audit batch.');
        $s=$this->db->prepare("SELECT source_external_id FROM mos_legacy_entity_map
            WHERE workspace_id=? AND source_system='mautic' AND source_entity_type='contact'
              AND source_external_id REGEXP '^[1-9][0-9]*$'
              AND CAST(source_external_id AS UNSIGNED)>?
            ORDER BY CAST(source_external_id AS UNSIGNED) ASC LIMIT ".($limit+1));
        $s->execute([(string)$workspace,$afterId]);
        $ids=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
        $hasMore=count($ids)>$limit;
        $selected=array_slice($ids,0,$limit);
        $counts=[];
        foreach($selected as $id){
            $decision=$this->auditOne($workspace,$id);
            $counts[$decision['status']]=($counts[$decision['status']]??0)+1;
        }
        return ['seen'=>count($selected),
            'next_after_id'=>$selected===[]?$afterId:max($selected),
            'has_more'=>$hasMore,'status_counts'=>$counts];
    }
}