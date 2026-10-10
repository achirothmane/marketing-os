<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use MarketingOS\Contracts\Id\{WorkspaceId,EvidenceId};
use MarketingOS\Identity\Bridge\{PdoMauticContactSnapshotReader,PdoMauticSourceAuditor,PdoMauticContactBridge};
use MarketingOS\Identity\Persistence\{PdoSchemaMigrator,PdoPersonStore,PdoCanonicalContactImporter,PdoLegacyContactRegistry};

$db=mos_test_connection();(new PdoSchemaMigrator($db))->migrate();
$key=getenv('MOS_SNAPSHOT_HMAC_KEY');
if(!is_string($key)||strlen($key)<32)throw new RuntimeException('HMAC test key required.');
$reader=new PdoMauticContactSnapshotReader($db,$key);
$auditor=new PdoMauticSourceAuditor($db,$reader);
$w=WorkspaceId::generate();$w2=WorkspaceId::generate();
$store=new PdoPersonStore($db);$store->createWorkspace($w);$store->createWorkspace($w2);
$bridge=new PdoMauticContactBridge($db,$reader,new PdoCanonicalContactImporter($db));
$pass=0;$fail=0;
function chk406(string $label,Closure $fn):void{
 global $pass,$fail;
 try{$fn();echo "PASS ".$label."\n";$pass++;}
 catch(Throwable $e){echo "FAIL ".$label.": ".$e->getMessage()."\n";$fail++;}
}
function yes406(bool $condition):void{if(!$condition)throw new RuntimeException('Assertion failed.');}
function add406(PDO $db,string $name='Baseline'):int{
 $s=$db->prepare("INSERT INTO leads(email,firstname,lastname,date_added,is_published,points)
   VALUES(?,?, 'Contact',UTC_TIMESTAMP(),1,0)");
 $s->execute(['c406-'.bin2hex(random_bytes(7)).'@example.invalid',$name]);
 return (int)$db->lastInsertId();
}
function count406(PDO $db,string $table):int{
 if(!in_array($table,['mos_person','mos_legacy_entity_map','mos_evidence','mos_domain_event',
 'mos_outbox','mos_source_reconciliation_case','mos_source_reconciliation_observation'],true)){
  throw new RuntimeException('Invalid table.');
 }
 return (int)$db->query("SELECT COUNT(*) FROM ".$table)->fetchColumn();
}
function auditCase406(PDO $db,WorkspaceId $w,int $id):array{
 $s=$db->prepare("SELECT status,revision,missing_observations,observed_fingerprint
  FROM mos_source_reconciliation_case WHERE workspace_id=? AND source_external_id=?");
 $s->execute([(string)$w,(string)$id]);$r=$s->fetch(PDO::FETCH_ASSOC);
 if($r===false)throw new RuntimeException('No reconciliation case.');
 return $r;
}
$id=add406($db);$bridge->importPersisted($w,$id);
chk406('migrations 004 and 005 replay with immutable checksums',function()use($db){
 (new PdoSchemaMigrator($db))->migrate();
 yes406((int)$db->query("SELECT COUNT(*) FROM mos_schema_migration")->fetchColumn()===6);
});
chk406('matching persisted source generates one observed MATCH case',function()use($auditor,$db,$w,$id){
 $r=$auditor->auditOne($w,$id);
 yes406($r['status']==='MATCH'&&$r['revision']===1&&$r['changed']);
 yes406(count406($db,'mos_source_reconciliation_observation')===1);
});
chk406('repeat identical observation is stable and does not inflate history',function()use($auditor,$db,$w,$id){
 $r=$auditor->auditOne($w,$id);
 yes406($r['status']==='MATCH'&&!$r['changed']&&$r['revision']===1);
 yes406(count406($db,'mos_source_reconciliation_observation')===1);
});
chk406('changed Contact is recorded, not applied to Person/Event/Outbox',function()use($auditor,$db,$w,$id){
 $before=array_map(fn($t)=>count406($db,$t),['mos_person','mos_legacy_entity_map','mos_domain_event','mos_outbox']);
 $s=$db->prepare("UPDATE leads SET firstname='Changed' WHERE id=?");$s->execute([$id]);
 $r=$auditor->auditOne($w,$id);
 yes406($r['status']==='SOURCE_CHANGED'&&$r['revision']===2);
 yes406($before===array_map(fn($t)=>count406($db,$t),['mos_person','mos_legacy_entity_map','mos_domain_event','mos_outbox']));
});
chk406('repeated changed fingerprint creates no additional observation',function()use($auditor,$w,$id,$db){
 $before=count406($db,'mos_source_reconciliation_observation');
 $r=$auditor->auditOne($w,$id);
 yes406(!$r['changed'] && count406($db,'mos_source_reconciliation_observation')===$before);
});
chk406('restored original fields transition SOURCE_CHANGED to MATCH',function()use($auditor,$db,$w,$id){
 $s=$db->prepare("UPDATE leads SET firstname='Baseline' WHERE id=?");$s->execute([$id]);
 $r=$auditor->auditOne($w,$id);
 yes406($r['status']==='MATCH'&&$r['revision']===3);
});
chk406('one missing read NEVER erases Person or confirms deletion',function()use($auditor,$db,$w,$id){
 $before=count406($db,'mos_domain_event');
 $delete=$db->prepare("DELETE FROM leads WHERE id=?");$delete->execute([$id]);
 $r=$auditor->auditOne($w,$id);
 yes406($r['status']==='SOURCE_MISSING_ONCE'&&$r['missing_observations']===1);
 yes406(count406($db,'mos_domain_event')===$before);
 $q=$db->prepare("SELECT p.state FROM mos_person p JOIN mos_legacy_entity_map m
  ON p.workspace_id=m.workspace_id AND p.id=m.person_id WHERE m.workspace_id=? AND m.source_external_id=?");
 $q->execute([(string)$w,(string)$id]);
 yes406($q->fetchColumn()==='ACTIVE');
});
chk406('immediate repeat cannot falsely confirm missing twice',function()use($auditor,$w,$id,$db){
 $before=count406($db,'mos_source_reconciliation_observation');
 $r=$auditor->auditOne($w,$id);
 yes406($r['status']==='SOURCE_MISSING_ONCE'&&!$r['changed']&&
 count406($db,'mos_source_reconciliation_observation')===$before);
});
chk406('time-separated second missing reading remains only candidate',function()use($auditor,$db,$w,$id){
 $s=$db->prepare("UPDATE mos_source_reconciliation_case
  SET last_observed_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 90 SECOND)
  WHERE workspace_id=? AND source_external_id=?");
 $s->execute([(string)$w,(string)$id]);
 $r=$auditor->auditOne($w,$id);
 yes406($r['status']==='SOURCE_MISSING_REPEATED'&&$r['missing_observations']===2);
 yes406(auditCase406($db,$w,$id)['status']==='SOURCE_MISSING_REPEATED');
});
chk406('missing candidate remains bounded across repeated observations',function()use($auditor,$db,$w,$id){
 $count=count406($db,'mos_source_reconciliation_observation');
 $s=$db->prepare("UPDATE mos_source_reconciliation_case SET last_observed_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 90 SECOND)
  WHERE workspace_id=? AND source_external_id=?");
 $s->execute([(string)$w,(string)$id]);
 $r=$auditor->auditOne($w,$id);
 yes406($r['missing_observations']===2&&!$r['changed']);
 yes406(count406($db,'mos_source_reconciliation_observation')===$count);
});
chk406('reappearing source is observed without silent merge or erasure',function()use($db,$auditor,$w,$id){
 $s=$db->prepare("INSERT INTO leads(id,email,firstname,lastname,date_added,is_published,points)
 VALUES(?,?, 'Reappeared','Contact',UTC_TIMESTAMP(),1,0)");
 $s->execute([$id,'return-'.bin2hex(random_bytes(6)).'@example.invalid']);
 $r=$auditor->auditOne($w,$id);
 yes406($r['status']==='SOURCE_CHANGED'&&$r['missing_observations']===0);
});
chk406('C4-03 legacy mapping lacking evidence is never auto-promoted',function()use($db,$auditor,$w){
 $other=add406($db,'Legacy');
 (new PdoLegacyContactRegistry($db))->registerMauticContact($w,$other,EvidenceId::generate());
 $r=$auditor->auditOne($w,$other);
 yes406($r['status']==='UNVERIFIED_LEGACY');
});
chk406('one source ID mapped in two workspaces has separate verdicts',function()use($bridge,$auditor,$w,$w2,$id,$db){
 $bridge->importPersisted($w2,$id);
 $r=$auditor->auditOne($w2,$id);
 yes406($r['status']==='MATCH');
 $a=auditCase406($db,$w,$id);
 yes406($a['status']==='SOURCE_CHANGED');
});
chk406('unmapped source contact refuses and does not create review case',function()use($db,$auditor,$w){
 $unmapped=add406($db,'Unmapped');
 $before=count406($db,'mos_source_reconciliation_case');
 $denied=false;
 try{$auditor->auditOne($w,$unmapped);}catch(DomainException $e){$denied=true;}
 yes406($denied&&count406($db,'mos_source_reconciliation_case')===$before);
});
chk406('source database read error cannot be interpreted as deletion',function()use($db,$w,$id){
 $broken=new PdoMauticContactSnapshotReader($db,getenv('MOS_SNAPSHOT_HMAC_KEY'),'unavailable_');
 $audit=new PdoMauticSourceAuditor($db,$broken);
 $caught=false;
 try{$audit->auditOne($w,$id);}catch(PDOException $e){$caught=true;}
 yes406($caught);
});
chk406('failure after case update before immutable observation rolls back both',function()use($db,$w,$id,$auditor){
 $before=auditCase406($db,$w,$id);
 $n=count406($db,'mos_source_reconciliation_observation');
 $s=$db->prepare("UPDATE leads SET firstname='OneMoreChange' WHERE id=?");$s->execute([$id]);
 $caught=false;
 try{$auditor->auditOne($w,$id,static function(string $stage):void{
   if($stage==='after_case_before_observation')throw new RuntimeException('INJECTED');
 });}catch(RuntimeException $e){$caught=$e->getMessage()==='INJECTED';}
 $after=auditCase406($db,$w,$id);
 yes406($caught && $after===$before && count406($db,'mos_source_reconciliation_observation')===$n);
});
chk406('bounded scan of mapped IDs detects removed records too',function()use($auditor,$w,$db){
 $s=$auditor->auditMappedBatch($w,0,500);
 yes406($s['seen']>=2 && $s['next_after_id']>0);
 yes406($s['status_counts']['SOURCE_CHANGED']>=1);
});
chk406('narrow pagination returns cursor and reports more mapped IDs',function()use($auditor,$w){
 $s=$auditor->auditMappedBatch($w,0,1);
 yes406($s['seen']===1 && $s['has_more'] && $s['next_after_id']>0);
});
chk406('source auditor does not expose source email or names in history',function()use($db){
 $s=$db->query("SELECT source_external_id,status,observed_fingerprint FROM mos_source_reconciliation_observation");
 foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){
   $txt=json_encode($row,JSON_THROW_ON_ERROR);
   yes406(!str_contains($txt,'@example.invalid')&&!str_contains($txt,'Reappeared')&&!str_contains($txt,'OneMoreChange'));
 }
});
echo "C4-06 SUMMARY $pass passed, $fail failed\n";
exit($fail===0?0:1);
