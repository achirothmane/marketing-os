<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Bridge;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use MarketingOS\Contracts\Id\WorkspaceId;

/**
 * Commit-safe recovery discovery using committed Mautic database reads.
 *
 * LEAD_POST_SAVE is not considered a source commit receipt; a separate worker
 * can independently rescan all persisted IDs, even after a missed hook/crash.
 *
 * Cursor is a progress hint, NOT a claim that all lower IDs were committed.
 * End-of-sweep wraps to zero; repeated sweeps recover delayed commits/holes.
 */
final readonly class PdoMauticContactReconciler {
    public function __construct(
        private PDO $db,
        private PdoMauticContactSnapshotReader $reader,
        private PdoMauticContactBridge $bridge
    ) {}

    /** @return array{seen:int,created:int,reused:int,blocked:int,wrapped:bool,cursor:int,reason_counts:array<string,int>} */
    public function scanOnce(WorkspaceId $workspace,int $batchSize=100,?callable $checkpoint=null):array {
        if($batchSize<1||$batchSize>1000)throw new InvalidArgumentException('Batch size must be 1..1000.');
        if($this->db->inTransaction())throw new RuntimeException('Must scan only outside source transactions.');
        $lockName='mos_c405b_'.substr(hash('sha256',(string)$workspace),0,32);
        $acquired=(int)$this->db->query("SELECT GET_LOCK(".$this->db->quote($lockName).",0)")->fetchColumn();
        if($acquired!==1)throw new RuntimeException('SCAN_BUSY');
        try{
            // Requires mos_workspace row. FK prevents scanning unknown workspaces.
            $s=$this->db->prepare("INSERT IGNORE INTO mos_contact_scan_cursor
               (workspace_id,last_seen_id,sweep_count,updated_at)
               VALUES (?,0,0,UTC_TIMESTAMP(6))");
            $s->execute([(string)$workspace]);
            $s=$this->db->prepare("SELECT last_seen_id FROM mos_contact_scan_cursor WHERE workspace_id=?");
            $s->execute([(string)$workspace]);
            $cursor=$s->fetchColumn();
            if($cursor===false)throw new RuntimeException('UNKNOWN_WORKSPACE');
            $cursor=(int)$cursor;

            $ids=$this->reader->contactIdsAfter($cursor,$batchSize);
            $created=0;$reused=0;$blocked=0;$reasons=[];
            foreach($ids as $id){
                try {
                    $result=$this->bridge->importPersisted($workspace,$id);
                    if($result->created)$created++;else $reused++;
                }catch(RuntimeException $e){
                    $kind=$e->getMessage();
                    if(!in_array($kind,[
                        'CONTACT_NOT_FOUND_OR_NOT_PERSISTED',
                        'LEGACY_MAPPING_LACKS_EVIDENCE',
                        'EVIDENCE_SOURCE_CONFLICT',
                        'CHANGED_CONTACT_NEEDS_C5_RECONCILIATION',
                        'CONCURRENT_SNAPSHOT_CONFLICT'
                    ],true))throw $e;
                    $blocked++;
                    $reasons[$kind]=($reasons[$kind]??0)+1;
                }
            }
            // Testing failure injection: all imports are individually committed, but cursor
            // is still old. A crash/restart MUST replay rather than create duplicates.
            if($checkpoint!==null)$checkpoint('after_import_before_cursor');
            // Failures to update cursor cause safe replay; importer deduplicates.
            $wrapped=$ids===[];
            $next=$wrapped?0:max($ids);
            $s=$this->db->prepare("UPDATE mos_contact_scan_cursor SET last_seen_id=?,
                sweep_count=sweep_count+?, updated_at=UTC_TIMESTAMP(6) WHERE workspace_id=? AND last_seen_id=?");
            $s->execute([$next,$wrapped?1:0,(string)$workspace,$cursor]);
            if($s->rowCount()!==1)throw new RuntimeException('Cursor changed unexpectedly; safe rescan required.');
            return ['seen'=>count($ids),'created'=>$created,'reused'=>$reused,
                'blocked'=>$blocked,'wrapped'=>$wrapped,'cursor'=>$next,'reason_counts'=>$reasons];
        }finally{
            $s=$this->db->prepare('SELECT RELEASE_LOCK(?)');
            $s->execute([$lockName]);
        }
    }
}
