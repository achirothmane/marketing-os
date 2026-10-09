<?php
declare(strict_types=1);
require dirname(__DIR__).'/tests/bootstrap.php';
use MarketingOS\Identity\Persistence\PdoSchemaMigrator;
$db=mos_test_connection();
(new PdoSchemaMigrator($db))->migrate();
echo "C4-03 migration 001 applied or already verified.\n";
