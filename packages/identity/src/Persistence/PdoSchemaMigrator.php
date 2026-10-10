<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Persistence;
use PDO;
use RuntimeException;

/** Explicit, checksum-guarded schema migration. MySQL DDL is NOT transactional. */
final readonly class PdoSchemaMigrator {
    private const PLANS = [
        '001_person_legacy_mapping' => ['mos_workspace','mos_person','mos_legacy_entity_map'],
        '002_evidence_event_outbox_inbox' => ['mos_evidence','mos_domain_event','mos_outbox','mos_inbox'],
        '003_contact_scan_cursor' => ['mos_contact_scan_cursor'],
        '004_contact_source_reconciliation' => ['mos_source_reconciliation_case','mos_source_reconciliation_observation'],
        '005_messenger_worker_runtime' => ['mos_messenger_messages','mos_identity_projection','mos_messenger_failure'],
        '006_encrypted_wire_quarantine' => ['mos_messenger_wire_quarantine'],
    ];
    public function __construct(private PDO $db) {}
    public function migrate(): void {
        $lock=$this->db->query("SELECT GET_LOCK('mos_schema_migrations',10)")->fetchColumn();
        if((int)$lock!==1)throw new RuntimeException('Unable to acquire migration advisory lock.');
        try{
            $this->db->exec("CREATE TABLE IF NOT EXISTS mos_schema_migration (
                version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
                checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                applied_at DATETIME(6) NOT NULL
            ) ENGINE=InnoDB");
            foreach(self::PLANS as $version=>$tables){
                $path=dirname(__DIR__,2).'/migrations/'.$version.'.sql';
                $sql=file_get_contents($path);
                if($sql===false)throw new RuntimeException("Missing migration: $version");
                $hash=hash('sha256',$sql);
                $stmt=$this->db->prepare("SELECT checksum FROM mos_schema_migration WHERE version=?");
                $stmt->execute([$version]);
                $existing=$stmt->fetchColumn();
                if($existing!==false){
                    if($existing!==$hash)throw new RuntimeException("Migration checksum changed: $version");
                    $this->assertTablesPresent($tables);
                    continue;
                }
                // InnoDB DDL commits implicitly. A mid-migration interruption is
                // a detectable partial schema requiring operator reconciliation.
                foreach(explode(';',$sql) as $ddl){
                    if(trim($ddl)!=='')$this->db->exec(trim($ddl));
                }
                $this->assertTablesPresent($tables);
                $s=$this->db->prepare("INSERT INTO mos_schema_migration(version,checksum,applied_at)
                    VALUES(?,?,UTC_TIMESTAMP(6))");
                $s->execute([$version,$hash]);
            }
        }finally{
            $this->db->query("SELECT RELEASE_LOCK('mos_schema_migrations')");
        }
    }
    private function assertTablesPresent(array $tables):void {
        $s=$this->db->prepare("SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema=DATABASE() AND table_name=?");
        foreach($tables as $table){
            $s->execute([$table]);
            if((int)$s->fetchColumn()!==1)throw new RuntimeException("Incomplete schema: $table");
        }
    }
}