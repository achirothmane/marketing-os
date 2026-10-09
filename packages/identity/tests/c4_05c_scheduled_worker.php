<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Persistence\{PdoSchemaMigrator,PdoPersonStore,PdoCanonicalContactImporter};
use MarketingOS\Identity\Bridge\{
 PdoMauticContactSnapshotReader,PdoMauticContactBridge,
 PdoMauticContactReconciler,BoundedShadowScanWorker
};

$db=mos_test_connection();
(new PdoSchemaMigrator($db))->migrate();
$w=WorkspaceId::generate();
(new PdoPersonStore($db))->createWorkspace($w);
$key=getenv('MOS_SNAPSHOT_HMAC_KEY');
$reader=new PdoMauticContactSnapshotReader($db,$key);
$bridge=new PdoMauticContactBridge($db,$reader,new PdoCanonicalContactImporter($db));
$scanner=new PdoMauticContactReconciler($db,$reader,$bridge);
$worker=new BoundedShadowScanWorker($scanner);
$passed=0;$failed=0;
function ckC(string $title,Closure $cb):void{
  global $passed,$failed;
  try{$cb();echo "PASS ".$title."\n";$passed++;}
  catch(Throwable $e){echo "FAIL ".$title.": ".$e->getMessage()."\n";$failed++;}
}
function assureC(bool $yes):void{if(!$yes)throw new RuntimeException('Assertion failed.');}
function addContactC(PDO $db):int {
 $s=$db->prepare("INSERT INTO leads (email,firstname,lastname,date_added,is_published,points)
   VALUES(?,'Bounded','Fixture',UTC_TIMESTAMP(),1,0)");
 $s->execute(['scheduled-'.bin2hex(random_bytes(8)).'@example.invalid']);
 return (int)$db->lastInsertId();
}
function personForC(PDO $db,WorkspaceId $w,int $id):?string {
 $s=$db->prepare("SELECT person_id FROM mos_legacy_entity_map WHERE workspace_id=? AND source_system='mautic' AND source_external_id=?");
 $s->execute([(string)$w,(string)$id]);$v=$s->fetchColumn();
 return $v===false?null:(string)$v;
}
$fixture=addContactC($db);
ckC('bounded worker executes at most one batch',function()use($worker,$w){
 $s=$worker->run($w,1,1,20);
 assureC($s['batches']===1 && $s['seen']===1 && !$s['wrapped']);
});
ckC('worker resumes and finishes sweep without extra iteration',function()use($worker,$w,$db,$fixture){
 $s=$worker->run($w,1000,5,20);
 assureC($s['wrapped'] && $s['batches']<=2 && $s['batches']>=1);
 assureC(personForC($db,$w,$fixture)!==null);
});
ckC('repeat from start does not create duplicate Person',function()use($worker,$w,$db,$fixture){
 $before=personForC($db,$w,$fixture);
 $worker->run($w,1000,5,20);
 assureC(personForC($db,$w,$fixture)===$before);
});
ckC('invalid batch, budget or seconds are rejected fail-closed',function()use($worker,$w){
 foreach([[0,1,30],[1,0,30],[1,1,0],[1001,1,30],[1,101,30],[1,1,301]] as $values){
  $throws=false;
  try{$worker->run($w,...$values);}catch(InvalidArgumentException $e){$throws=true;}
  assureC($throws);
 }
});
ckC('concurrent runner is denied by workspace-scoped advisory lock',function()use($db,$scanner,$w){
 $other=mos_test_connection();
 $lock='mos_c405b_'.substr(hash('sha256',(string)$w),0,32);
 $q=$other->prepare("SELECT GET_LOCK(?,0)");$q->execute([$lock]);
 assureC((int)$q->fetchColumn()===1);
 try{
   $refused=false;
   try{$scanner->scanOnce($w,1);}catch(RuntimeException $e){$refused=$e->getMessage()==='SCAN_BUSY';}
   assureC($refused);
 }finally{$s=$other->prepare('SELECT RELEASE_LOCK(?)');$s->execute([$lock]);}
});
ckC('abrupt crash after commit, before checkpoint recovers exactly one Person and event',function()use($db,$w,$worker,$fixture){
 // Dedicated workspace, no existing MOS mappings; crash terminates process
 // with exit code 77 before cursor UPDATE.
 $crashWorkspace=WorkspaceId::generate();
 (new PdoPersonStore($db))->createWorkspace($crashWorkspace);
 $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/c4_05c_crash_worker.php').' '.escapeshellarg((string)$crashWorkspace);
 $proc=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($proc))throw new RuntimeException('Could not spawn crash process');
 fclose($pipes[0]);stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
 $status=proc_close($proc);
 assureC($status===77);
 $cursor=$db->prepare("SELECT last_seen_id FROM mos_contact_scan_cursor WHERE workspace_id=?");
 $cursor->execute([(string)$crashWorkspace]);
 assureC((int)$cursor->fetchColumn()===0);
 $person=personForC($db,$crashWorkspace,$fixture);
 assureC($person!==null);
 $query=$db->prepare("SELECT COUNT(*) FROM mos_domain_event WHERE workspace_id=?");
 $query->execute([(string)$crashWorkspace]);$events=(int)$query->fetchColumn();
 assureC($events>=1);
 // Restart from a new connection: source rows replay, no duplicate entities
 // and cursor now advances.
 $again=mos_test_connection();
 $r=new PdoMauticContactSnapshotReader($again,getenv('MOS_SNAPSHOT_HMAC_KEY'));
 $b=new PdoMauticContactBridge($again,$r,new PdoCanonicalContactImporter($again));
 $scan=new BoundedShadowScanWorker(new PdoMauticContactReconciler($again,$r,$b));
 $out=$scan->run($crashWorkspace,1000,5,30);
 assureC($out['wrapped'] && personForC($db,$crashWorkspace,$fixture)===$person);
 $query->execute([(string)$crashWorkspace]);assureC((int)$query->fetchColumn()===$events);
});
ckC('scheduled CLI is disabled unless both explicit opt-ins are present',function()use($w){
 $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/run_scheduled_shadow_scan.php').' '.escapeshellarg((string)$w);
 $env=getenv();$env['MOS_BRIDGE_MODE']='SHADOW';$env['MOS_SCHEDULED_SHADOW_ENABLED']='0';
 $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
 if(!is_resource($proc))throw new RuntimeException('Cannot execute scheduled CLI');
 fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
 fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);
 assureC($exit===1 && $stdout==='' && str_contains($stderr,'refused'));
});
ckC('enabled scheduled CLI runs bounded scan and reports PII-free JSON',function()use($w){
 $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/run_scheduled_shadow_scan.php').' '.escapeshellarg((string)$w);
 $env=getenv();
 $env['MOS_BRIDGE_MODE']='SHADOW';
 $env['MOS_SCHEDULED_SHADOW_ENABLED']='1';
 $env['MOS_SCAN_BATCH_SIZE']='1000';
 $env['MOS_SCAN_MAX_BATCHES']='5';
 $env['MOS_SCAN_MAX_SECONDS']='20';
 $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
 if(!is_resource($proc))throw new RuntimeException('Cannot start enabled CLI');
 fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
 fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);
 assureC($exit===0 && $stderr==='');
 $out=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);
 assureC($out['batches']<=5 && $out['blocked']===0 && $out['wrapped']);
 assureC(!str_contains($stdout,'@example.invalid') && !str_contains($stdout,getenv('MOS_SNAPSHOT_HMAC_KEY')));
});
echo "C4-05C SUMMARY $passed passed, $failed failed\n";
exit($failed===0?0:1);