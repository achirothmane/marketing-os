<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Runtime;

use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Transport\MosWireQuarantineKeyManager;
use PDO;
use RuntimeException;

/**
 * C4-07B3B1: bounded, strictly read-only local SHADOW preflight.
 * No migration, wire-decryption output, redrive, scheduler or external effects.
 */
final readonly class MosShadowReadinessPreflight {
    private const MIGRATIONS = [
        '001_person_legacy_mapping',
        '002_evidence_event_outbox_inbox',
        '003_contact_scan_cursor',
        '004_contact_source_reconciliation',
        '005_messenger_worker_runtime',
        '006_encrypted_wire_quarantine',
    ];
    public function __construct(private PDO $db) {}

    /** @return array<string,mixed> */
    public function assess(WorkspaceId $workspace, string $activeKeyId, string $key, int $pageLimit = 100, int $maxPages = 10): array {
        if ($this->db->inTransaction()) throw new RuntimeException('PREFLIGHT_TRANSACTION_ACTIVE');
        if ($pageLimit < 1 || $pageLimit > 100 || $maxPages < 1 || $maxPages > 10) {
            throw new RuntimeException('PREFLIGHT_BOUNDS_INVALID');
        }
        if (!preg_match('/^[a-zA-Z0-9_.-]{1,32}$/D', $activeKeyId) ||
            strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('PREFLIGHT_KEY_INVALID');
        }
        $this->assertSchema();
        $q = $this->db->prepare('SELECT id FROM mos_workspace WHERE id=?');
        $q->execute([(string)$workspace]);
        if ($q->fetchColumn() === false) throw new RuntimeException('PREFLIGHT_UNKNOWN_WORKSPACE');
        $queue = 'mos_identity_'.(string)$workspace;
        $count = static function(PDO $db, string $sql, array $params): int {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        };
        $manager = new MosWireQuarantineKeyManager($this->db);
        $inventory = $manager->inventory($workspace);
        $otherKeys = $inventory['total'] - ($inventory['key_counts'][$activeKeyId] ?? 0);
        $cursor = 0;
        $verified = 0;
        $complete = false;
        for ($page = 0; $page < $maxPages; $page++) {
            $v = $manager->verify($workspace, $activeKeyId, $key, $pageLimit, $cursor);
            $verified += $v['verified'];
            $cursor = $v['next_cursor'];
            if (!$v['has_more']) { $complete = true; break; }
        }
        // An unverified page is NEVER counted as authenticated evidence.
        $pending = $count($this->db,
            'SELECT COUNT(*) FROM mos_outbox WHERE workspace_id=? AND published_at IS NULL', [(string)$workspace]);
        $queued = $count($this->db,
            'SELECT COUNT(*) FROM mos_messenger_messages WHERE queue_name=?', [$queue]);
        $oversized = $count($this->db,
            'SELECT COUNT(*) FROM mos_messenger_messages WHERE queue_name=? AND (OCTET_LENGTH(body)+OCTET_LENGTH(headers))>262144', [$queue]);
        $failures = $count($this->db,
            'SELECT COUNT(*) FROM mos_messenger_failure WHERE workspace_id=? AND quarantined_at IS NOT NULL', [(string)$workspace]);
        $review = [];
        if ($otherKeys > 0) $review[] = 'ARCHIVES_WITH_NONACTIVE_KEYS';
        if (!$complete) $review[] = 'ACTIVE_ARCHIVE_VERIFICATION_INCOMPLETE';
        if ($inventory['total'] > 0) $review[] = 'WIRE_QUARANTINE_REVIEW_REQUIRED';
        if ($failures > 0) $review[] = 'POINTER_QUARANTINE_REVIEW_REQUIRED';
        if ($oversized > 0) $review[] = 'OVERSIZED_QUEUE_WIRE_BLOCKS_SUPERVISION';
        if ($queued >= 1000) $review[] = 'QUEUE_BACKPRESSURE';
        return [
            'decision' => $review === [] ? 'LOCAL_PREFLIGHT_PASS' : 'REVIEW_REQUIRED',
            'scope' => 'LOCAL_READ_ONLY_SHADOW_NOT_DEPLOYMENT',
            'workspace_id' => (string)$workspace,
            'schema_versions_verified' => count(self::MIGRATIONS),
            'active_archive_verified' => $verified,
            'active_archive_complete' => $complete,
            'other_key_archive_count' => $otherKeys,
            'wire_quarantine_count' => $inventory['total'],
            'pointer_quarantine_count' => $failures,
            'outbox_pending' => $pending,
            'queue_depth' => $queued,
            'oversized_queue_count' => $oversized,
            'review_reasons' => $review,
        ];
    }

    private function assertSchema(): void {
        try {
            $q = $this->db->query('SELECT version, checksum FROM mos_schema_migration');
            $actual = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Throwable $e) {
            throw new RuntimeException('PREFLIGHT_SCHEMA_MISSING', 0, $e);
        }
        if (count($actual) !== count(self::MIGRATIONS)) throw new RuntimeException('PREFLIGHT_SCHEMA_VERSION_MISMATCH');
        foreach (self::MIGRATIONS as $version) {
            $path = dirname(__DIR__,2).'/migrations/'.$version.'.sql';
            $hash = hash_file('sha256', $path);
            if (!is_string($hash) || !isset($actual[$version]) || !hash_equals($actual[$version],$hash)) {
                throw new RuntimeException('PREFLIGHT_SCHEMA_CHECKSUM_MISMATCH');
            }
        }
        foreach (['mos_workspace','mos_outbox','mos_messenger_messages',
                  'mos_messenger_failure','mos_messenger_wire_quarantine'] as $table) {
            $q = $this->db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
            $q->execute([$table]);
            if ((int)$q->fetchColumn() !== 1) throw new RuntimeException('PREFLIGHT_SCHEMA_MISSING');
        }
    }
}
