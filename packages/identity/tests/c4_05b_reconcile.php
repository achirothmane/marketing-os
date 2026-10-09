<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Persistence\{PdoSchemaMigrator,PdoPersonStore,PdoCanonicalContactImporter,PdoLegacyContactRegistry};
use MarketingOS\Identity\Bridge\{PdoMauticContactSnapshotReader,PdoMauticContactBridge,PdoMauticContactReconciler};
use MarketingOS\Contracts\Id\EvidenceId;
$db=mos_test_connection();(new PdoSchemaMigrator($db))->migrate();
$w=WorkspaceId::generate();(new PdoPersonStore($db))->createWorkspace($w);
$reader=new PdoMauticContactSnapshotReader($db,getenv('MOS_SNAPSHOT_HMAC_KEY'));
$bridge=new PdoMauticContactBridge($db,$reader,new PdoCanonicalContactImporter($db));
$scan=new PdoMauticContactReconciler($db,$reader,$bridge);
$pass=0;$fail=0;
function checkScan(string $label,Closure $fn):void{
  global $pass,$fail;
  try{$fn();echo "PASS ".$label."\n";$pass++;}
  catch(Throwable $e){echo "FAIL ".$label.": ".$e->getMessage()."\n";$fail++;}
}
function mustScan(bool $v):void{if(!$v)throw new RuntimeException('Assertion failed.');}
function leadScan(PDO $db):int {
  $s=$db->prepare("INSERT INTO leads (email,firstname,lastname,date_added,is_published,points) VALUES(?,'Probe','Fixture',UTC_TIMESTAMP(),1,0)");
  $s->execute(['scan-'.bin2hex(random_bytes(6)).'@example.invalid']);return (int)$db->lastInsertId();
}
function mappedScan(PDO $db,WorkspaceId $w,int $id):bool {
  $s=$db->prepare("SELECT COUNT(*) FROM mos_legacy_entity_map WHERE workspace_id=? AND source_external_id=?");
  $s->execute([(string)$w,(string)$id]);return (int)$s->fetchColumn()===1;
}
checkScan('committed Contact discovers and imports once',function()use($db,$w,$scan){
  $id=leadScan($db);$s=$scan->scanOnce($w,1000);
  mustScan($s['created']>=1 && mappedScan($db,$w,$id));
});
checkScan('cursor wraps and replays without duplicate Person',function()use($db,$w,$scan){
  $n=(int)$db->query("SELECT COUNT(*) FROM mos_person")->fetchColumn();
  mustScan($scan->scanOnce($w,1000)['wrapped']);
  $s=$scan->scanOnce($w,1000);
  mustScan($s['reused']>=1 && (int)$db->query("SELECT COUNT(*) FROM mos_person")->fetchColumn()===$n);
});
checkScan('uncommitted Contact invisible and rolled-back source never maps',function()use($db,$w,$scan){
  $tx=mos_test_connection();$tx->beginTransaction();
  $id=leadScan($tx);
  try{$scan->scanOnce($w,1000);mustScan(!mappedScan($db,$w,$id));}
  finally{$tx->rollBack();}
  $scan->scanOnce($w,1000);$scan->scanOnce($w,1000);
  mustScan(!mappedScan($db,$w,$id));
});
checkScan('late lower-ID commit recovered after cursor wraps',function()use($db,$w,$scan){
  $tx=mos_test_connection();$tx->beginTransaction();
  $earlier=leadScan($tx);
  try{
    $later=leadScan($db);mustScan($later>$earlier);
    for($j=0;$j<5&&!mappedScan($db,$w,$later);$j++)$scan->scanOnce($w,1000);
    mustScan(mappedScan($db,$w,$later)&&!mappedScan($db,$w,$earlier));
    $tx->commit();
    for($j=0;$j<8&&!mappedScan($db,$w,$earlier);$j++)$scan->scanOnce($w,1000);
    mustScan(mappedScan($db,$w,$earlier));
  }finally{if($tx->inTransaction())$tx->rollBack();}
});
checkScan('legacy no-Evidence mapping blocked but other contacts progress',function()use($db,$w,$scan){
  $bad=leadScan($db);$good=leadScan($db);
  (new PdoLegacyContactRegistry($db))->registerMauticContact($w,$bad,EvidenceId::generate());
  $blocked=false;
  for($j=0;$j<8;$j++){
    $r=$scan->scanOnce($w,1000);
    if(($r['reason_counts']['LEGACY_MAPPING_LACKS_EVIDENCE']??0)>0)$blocked=true;
    if($blocked&&mappedScan($db,$w,$good))break;
  }
  mustScan($blocked&&mappedScan($db,$w,$good));
});
checkScan('modified Contact reported blocked without another Event',function()use($db,$w,$scan){
  $id=leadScan($db);
  for($j=0;$j<5&&!mappedScan($db,$w,$id);$j++)$scan->scanOnce($w,1000);
  mustScan(mappedScan($db,$w,$id));
  $n=(int)$db->query("SELECT COUNT(*) FROM mos_domain_event")->fetchColumn();
  $s=$db->prepare("UPDATE leads SET firstname='Changed' WHERE id=?");$s->execute([$id]);
  $blocked=false;
  for($j=0;$j<7;$j++){
    $r=$scan->scanOnce($w,1000);
    if(($r['reason_counts']['CHANGED_CONTACT_NEEDS_C5_RECONCILIATION']??0)>0){$blocked=true;break;}
  }
  mustScan($blocked&&(int)$db->query("SELECT COUNT(*) FROM mos_domain_event")->fetchColumn()===$n);
});
checkScan('unknown workspace cannot create cursor',function()use($scan){
  $failed=false;
  try{$scan->scanOnce(WorkspaceId::generate(),1);}catch(Throwable $e){$failed=true;}
  mustScan($failed);
});
echo "C4-05B RECONCILER SUMMARY $pass passed, $fail failed\n";
exit($fail===0?0:1);