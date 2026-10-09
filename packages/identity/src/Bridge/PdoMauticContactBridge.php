<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Bridge;
use PDO;
use RuntimeException;
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\ImportResult;
use MarketingOS\Identity\LegacyEntityRef;
use MarketingOS\Identity\Persistence\PdoCanonicalContactImporter;
use MarketingOS\Identity\Persistence\PdoLegacyContactRegistry;
/** Opt-in, post-persistence shadow bridge. No Mautic save-event side effect. */
final readonly class PdoMauticContactBridge {
    public function __construct(private PDO $db,private PdoMauticContactSnapshotReader $reader,
       private PdoCanonicalContactImporter $importer){}
    public function importPersisted(WorkspaceId $workspace,int $id):ImportResult {
       $fingerprint=$this->reader->fingerprint($workspace,$id);
       $mapping=(new PdoLegacyContactRegistry($this->db))
          ->find(LegacyEntityRef::mauticContact($workspace,$id));
       if($mapping!==null){
          $s=$this->db->prepare('SELECT content_sha256,source_locator FROM mos_evidence WHERE workspace_id=? AND id=?');
          $s->execute([(string)$workspace,(string)$mapping->evidenceId]);
          $row=$s->fetch(PDO::FETCH_ASSOC);
          if($row===false)throw new RuntimeException('LEGACY_MAPPING_LACKS_EVIDENCE');
          if($row['source_locator']!=='mautic://contact/'.$id)throw new RuntimeException('EVIDENCE_SOURCE_CONFLICT');
          if(!hash_equals($row['content_sha256'],$fingerprint))throw new RuntimeException('CHANGED_CONTACT_NEEDS_C5_RECONCILIATION');
       }
       $r=$this->importer->import($workspace,$id,$fingerprint);
       $s=$this->db->prepare('SELECT content_sha256 FROM mos_evidence WHERE workspace_id=? AND id=?');
       $s->execute([(string)$workspace,(string)$r->mapping->evidenceId]);
       $actual=$s->fetchColumn();
       if(!is_string($actual)||!hash_equals($actual,$fingerprint))throw new RuntimeException('CONCURRENT_SNAPSHOT_CONFLICT');
       return $r;
    }
}
