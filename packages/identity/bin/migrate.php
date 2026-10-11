<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/runtime.php';
use MarketingOS\Identity\Persistence\PdoSchemaMigrator;

// Schema administration uses a third, explicit credential profile; never use
// the read-only preflight or DML-only worker connection for DDL.
try {
    if (getenv('MOS_SCHEMA_MIGRATION_ENABLED') !== '1') {
        throw new RuntimeException('MIGRATION_DISABLED');
    }
    $db=mos_runtime_connection('MIGRATE');
    (new PdoSchemaMigrator($db))->migrate();
    echo "MOS checksummed schema migration complete.\n";
} catch (\Throwable $e) {
    $reason=in_array($e->getMessage(),['MIGRATION_DISABLED','RUNTIME_CREDENTIALS_MISSING',
       'RUNTIME_DSN_INVALID','RUNTIME_CONNECTION_REFUSED'],true)
       ?$e->getMessage():'MIGRATION_REFUSED';
    fwrite(STDERR,"MOS schema migration blocked: ".$reason."\n");
    exit(2);
}
