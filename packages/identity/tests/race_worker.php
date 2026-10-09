<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use MarketingOS\Identity\Persistence\PdoLegacyContactRegistry;
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Contracts\Id\EvidenceId;
try {
    if($argc!==4)throw new RuntimeException('Expected workspace, contact and start barrier.');
    $deadline=microtime(true)+12;
    while(!is_file($argv[3])&&microtime(true)<$deadline)usleep(10000);
    if(!is_file($argv[3]))throw new RuntimeException('Barrier timeout.');
    $reg=new PdoLegacyContactRegistry(mos_test_connection());
    $result=$reg->registerMauticContact(WorkspaceId::fromString($argv[1]),(int)$argv[2],EvidenceId::generate());
    echo (string)$result->personId."\n";
}catch(Throwable $e) {
    fwrite(STDERR,$e->getMessage()."\n");
    exit(1);
}