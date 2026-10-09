<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Persistence\PdoCanonicalContactImporter;
use MarketingOS\Identity\Bridge\{
    PdoMauticContactSnapshotReader,PdoMauticContactBridge,PdoMauticContactReconciler
};
// Test-only: run on isolated CI schema, deliberately terminate process without
// releasing advisory lock in PHP finally and WITHOUT checkpointing cursor.
if($argc!==2)exit(41);
$pdo=mos_test_connection();
$w=WorkspaceId::fromString($argv[1]);
$key=getenv('MOS_SNAPSHOT_HMAC_KEY');
if(!is_string($key)||strlen($key)<32)exit(42);
$reader=new PdoMauticContactSnapshotReader($pdo,$key,getenv('MOS_MAUTIC_TABLE_PREFIX')?:'');
$bridge=new PdoMauticContactBridge($pdo,$reader,new PdoCanonicalContactImporter($pdo));
(new PdoMauticContactReconciler($pdo,$reader,$bridge))->scanOnce($w,1000,
    static function(string $checkpoint):void {
        if($checkpoint==='after_import_before_cursor')exit(77);
    }
);
exit(43);