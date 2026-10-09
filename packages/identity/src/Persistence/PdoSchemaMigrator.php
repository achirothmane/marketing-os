<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Persistence;
use PDO;
use RuntimeException;
/** Explicit, non-destructive, locked initial migration. Not auto-run by Mautic. */
final readonly class PdoSchemaMigrator {
    public function __construct(private PDO $db) {}
    public function migrate(): void {
        $sql=file_get_contents(dirname(__DIR__,2).'/migrations/001_person_legacy_mapping.sql');
        if($sql===false)throw new RuntimeException('Missing migration source.');
        $hash=hash('sha256',$sql);
        $lock=$this->db->query("SELECT GET_LOCK('mos_c4_03_schema',10)")->fetchColumn();
        if((int)$lock!==1)throw new RuntimeException('Unable to acquire schema lock.');
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS mos_schema_migration (
                version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
                checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                applied_at DATETIME(6) NOT NULL
              ) ENGINE=InnoDB");
            $statement=$this->db->prepare("SELECT checksum FROM mos_schema_migration WHERE version = ?");
            $statement->execute(['001_person_legacy_mapping']);
            $existing=$statement->fetchColumn();
            if($existing!==false){
                if($existing!==$hash)throw new RuntimeException('Migration checksum drift: migration 001 changed.');
                $this->assertTablesPresent();
                return;
            }
            // MySQL DDL commits implicitly; failure leaves observable partial work,
            // not a pretend rolled-back transactional migration.
            foreach(explode(';',$sql) as $ddl){
                if(trim($ddl)!=='')$this->db->exec(trim($ddl));
            }
            $this->assertTablesPresent();
            $stmt=$this->db->prepare("INSERT INTO mos_schema_migration (version,checksum,applied_at) VALUES (?,?,UTC_TIMESTAMP(6))");
            $stmt->execute(['001_person_legacy_mapping',$hash]);
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('mos_c4_03_schema')");
        }
    }
    private function assertTablesPresent(): void {
        foreach(['mos_workspace','mos_person','mos_legacy_entity_map'] as $table){
            $s=$this->db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
            $s->execute([$table]);
            if((int)$s->fetchColumn()!==1)throw new RuntimeException("Missing migration table: $table");
        }
    }
}
