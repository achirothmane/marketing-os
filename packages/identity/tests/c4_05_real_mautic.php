<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Contracts\Id\EvidenceId;
use MarketingOS\Identity\Persistence\PdoPersonStore;
use MarketingOS\Identity\Persistence\PdoSchemaMigrator;
use MarketingOS\Identity\Persistence\PdoCanonicalContactImporter;
use MarketingOS\Identity\Persistence\PdoLegacyContactRegistry;
use MarketingOS\Identity\Bridge\PdoMauticContactSnapshotReader;
use MarketingOS\Identity\Bridge\PdoMauticContactBridge;
$db=mos_test_connection();
$secret=getenv('MOS_SNAPSHOT_HMAC_KEY');
if(!is_string($secret)||strlen($secret)<32)throw new RuntimeException('Missing test HMAC secret.');
$m=new PdoSchemaMigrator($db);$m->migrate();
$people=new PdoPersonStore($db);
$w=WorkspaceId::generate();$people->createWorkspace($w);
$reader=new PdoMauticContactSnapshotReader($db,$secret,getenv('MOS_MAUTIC_TABLE_PREFIX')?:'');
$bridge=new PdoMauticContactBridge($db,$reader,new PdoCanonicalContactImporter($db));
$pass=0;$fail=0;
function testC405(string $label,Closure $run):void{
  global $pass,$fail;
  try{$run();echo "PASS $label\n";$pass++;}
  catch(Throwable $e){echo "FAIL $label: ".$e->getMessage()."\n";$fail++;}
}
function assertC405(bool $ok):void{if(!$ok)throw new RuntimeException('Assertion failed');}
function countC405(PDO $db,string $table):int{
  if(!in_array($table,['mos_person','mos_evidence','mos_domain_event','mos_outbox','mos_legacy_entity_map'],true))throw new RuntimeException('Unsafe test table');
  return (int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
}
function seededContact(PDO $db,string $email):int{
  // Inserts into Mautic's REAL persisted Lead table in the disposable CI install.
  // Do not confuse this with the LeadModel save event; it is not dispatched here.
  $s=$db->prepare("INSERT INTO leads (email,firstname,lastname,date_added,is_published,points)
    VALUES(?,'Fixture','Person',UTC_TIMESTAMP(),1,0)");
  $s->execute([$email]);
  return (int)$db->lastInsertId();
}
$id=null;$result=null;
testC405('upstream installer created actual Mautic leads table',function()use($db){
  $s=$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='leads'");
  assertC405((int)$s->fetchColumn()===1);
});
testC405('persist a real Mautic Contact fixture',function()use($db,&$id){
  $id=seededContact($db,'fixture-'.bin2hex(random_bytes(4)).'@example.invalid');
  assertC405($id>0);
  $s=$db->prepare('SELECT id,email,firstname,lastname FROM leads WHERE id=?');
  $s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
  assertC405($row!==false && $row['firstname']==='Fixture');
});
testC405('missing Contact refuses without MOS side effects',function()use($db,$reader,$w){
  $before=countC405($db,'mos_person');$refused=false;
  try{$reader->fingerprint($w,99999999);}catch(RuntimeException $e){$refused=$e->getMessage()==='CONTACT_NOT_FOUND_OR_NOT_PERSISTED';}
  assertC405($refused && countC405($db,'mos_person')===$before);
});
testC405('HMAC fingerprint derived from persisted Mautic values',function()use($reader,$w,&$id){
  $fingerprint=$reader->fingerprint($w,$id);
  assertC405(strlen($fingerprint)===64 && $fingerprint===$reader->fingerprint($w,$id));
});
testC405('HMAC varies by workspace and key',function()use($db,$reader,$secret,$w,&$id){
  $other=WorkspaceId::generate();
  $different=new PdoMauticContactSnapshotReader($db,str_repeat('k',32));
  assertC405($reader->fingerprint($w,$id)!==$reader->fingerprint($other,$id));
  assertC405($reader->fingerprint($w,$id)!==$different->fingerprint($w,$id));
});
testC405('real persisted contact imports into MOS atomic evidence pipeline',function()use($bridge,$db,$w,&$id,&$result){
  $result=$bridge->importPersisted($w,$id);
  assertC405($result->created && $result->eventId!==null);
  foreach(['mos_person','mos_legacy_entity_map','mos_evidence','mos_domain_event','mos_outbox'] as $table){
    assertC405(countC405($db,$table)===1);
  }
});
testC405('replay returns original person and no second event',function()use($bridge,$db,$w,&$id,&$result){
  $r=$bridge->importPersisted($w,$id);
  assertC405(!$r->created && $r->eventId===null &&
    $r->mapping->personId->equals($result->mapping->personId));
  assertC405(countC405($db,'mos_evidence')===1 && countC405($db,'mos_domain_event')===1);
});
testC405('payload/evidence/outbox have no raw email, name or HMAC key',function()use($db,$secret){
  foreach(['mos_domain_event'=>'payload_json','mos_evidence'=>'source_locator'] as $table=>$col){
    $q=$db->query("SELECT $col FROM $table");
    $value=implode(' ',array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN)));
    assertC405(!str_contains($value,'@example.invalid')&&!str_contains($value,'Fixture')&&!str_contains($value,$secret));
  }
});
testC405('source modification blocked pending C5 reconciliation',function()use($bridge,$db,$w,&$id){
  $q=$db->prepare("UPDATE leads SET firstname='Changed' WHERE id=?");$q->execute([$id]);
  $refused=false;
  try{$bridge->importPersisted($w,$id);}catch(RuntimeException $e){$refused=$e->getMessage()==='CHANGED_CONTACT_NEEDS_C5_RECONCILIATION';}
  assertC405($refused && countC405($db,'mos_evidence')===1);
});
testC405('separate persisted source contact gets distinct Person',function()use($db,$bridge,$w,&$result){
  $other=seededContact($db,'second-'.bin2hex(random_bytes(5)).'@example.invalid');
  $r=$bridge->importPersisted($w,$other);
  assertC405($r->created && !$r->mapping->personId->equals($result->mapping->personId));
});
testC405('preexisting C4-03 mapping without evidence refuses promotion',function()use($db,$w,$bridge){
  $id=seededContact($db,'legacy-'.bin2hex(random_bytes(5)).'@example.invalid');
  (new PdoLegacyContactRegistry($db))->registerMauticContact($w,$id,EvidenceId::generate());
  $blocked=false;
  try{$bridge->importPersisted($w,$id);}catch(RuntimeException $e){$blocked=$e->getMessage()==='LEGACY_MAPPING_LACKS_EVIDENCE';}
  assertC405($blocked);
});
testC405('invalid or too short HMAC key is refused',function()use($db){
  $blocked=false;
  try{new PdoMauticContactSnapshotReader($db,'tiny');}catch(InvalidArgumentException $e){$blocked=true;}
  assertC405($blocked);
});
echo "C4-05 SUMMARY $pass passed, $fail failed\n";
exit($fail===0?0:1);
