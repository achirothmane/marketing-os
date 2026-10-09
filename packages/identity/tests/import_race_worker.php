<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Persistence\PdoCanonicalContactImporter;
try{
  if($argc!==4)throw new RuntimeException('Expected workspace contact barrier');
  $deadline=microtime(true)+12;
  while(!file_exists($argv[3]) && microtime(true)<$deadline)usleep(10000);
  if(!file_exists($argv[3]))throw new RuntimeException('Missing race barrier.');
  $id=(int)$argv[2];
  $m=(new PdoCanonicalContactImporter(mos_test_connection()))
    ->import(WorkspaceId::fromString($argv[1]),$id,hash('sha256',"fixture:mautic-contact:$id"));
  echo (string)$m->mapping->personId."\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
