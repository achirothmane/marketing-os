<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/runtime.php';

use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Runtime\MosShadowReadinessPreflight;

/** Preflight does not start any worker. Never output secrets, SQL or wire bodies. */
try {
    if (getenv('MOS_PREFLIGHT_ENABLED') !== '1' ||
        getenv('MOS_QUEUE_MODE') !== 'SHADOW' ||
        getenv('MOS_BRIDGE_MODE') !== 'SHADOW') throw new RuntimeException('DISABLED');
    if ($argc !== 4) throw new RuntimeException('INVALID_ARGS');
    $workspace = WorkspaceId::fromString($argv[1]);
    if (!preg_match('/^[1-9][0-9]*$/D',$argv[3]) || (int)$argv[3] > 100) {
        throw new RuntimeException('INVALID_ARGS');
    }
    $active = $argv[2];
    $raw = getenv('MOS_DLQ_KEY_B64');
    $key = is_string($raw) ? base64_decode($raw,true) : false;
    if (!is_string($key) || strlen($key) !== 32) throw new RuntimeException('KEY_MISSING');
    $hmac = getenv('MOS_SNAPSHOT_HMAC_KEY');
    if (!is_string($hmac) || strlen($hmac) < 32) throw new RuntimeException('SOURCE_KEY_MISSING');
    $db = mos_runtime_connection('READ'); // Explicit READ credentials; no test/production fallback.
    $result = (new MosShadowReadinessPreflight($db))->assess($workspace,$active,$key,(int)$argv[3]);
    echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
    exit($result['decision'] === 'LOCAL_PREFLIGHT_PASS' ? 0 : 3);
} catch (\Throwable $error) {
    $allow = ['DISABLED','INVALID_ARGS','KEY_MISSING','SOURCE_KEY_MISSING',
        'PREFLIGHT_TRANSACTION_ACTIVE','PREFLIGHT_BOUNDS_INVALID','PREFLIGHT_KEY_INVALID',
        'PREFLIGHT_UNKNOWN_WORKSPACE','PREFLIGHT_SCHEMA_MISSING',
        'PREFLIGHT_SCHEMA_VERSION_MISMATCH','PREFLIGHT_SCHEMA_CHECKSUM_MISMATCH',
        'ARCHIVE_AUTHENTICATION_FAILED','ARCHIVE_SOURCE_MISMATCH'];
    $message = $error->getMessage();
    $reason = in_array($message,$allow,true) ? $message : 'PREFLIGHT_BLOCKED';
    fwrite(STDERR,"MOS SHADOW readiness refused: ".$reason."\n");
    exit(2);
}
