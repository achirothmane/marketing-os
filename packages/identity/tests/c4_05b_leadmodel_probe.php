<?php
declare(strict_types=1);
require dirname(__DIR__,3).'/vendor/autoload.php';
require __DIR__.'/bootstrap.php';
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Event\LeadEvent;
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Persistence\{PdoSchemaMigrator,PdoPersonStore,PdoCanonicalContactImporter};
use MarketingOS\Identity\Bridge\{PdoMauticContactSnapshotReader,PdoMauticContactBridge,PdoMauticContactReconciler};
$db=mos_test_connection();(new PdoSchemaMigrator($db))->migrate();
$w=WorkspaceId::generate();(new PdoPersonStore($db))->createWorkspace($w);
$reader=new PdoMauticContactSnapshotReader($db,getenv('MOS_SNAPSHOT_HMAC_KEY'));
$scan=new PdoMauticContactReconciler($db,$reader,new PdoMauticContactBridge($db,$reader,new PdoCanonicalContactImporter($db)));
$kernel=new AppKernel('prod',false);$kernel->boot();
$services=$kernel->getContainer();
$model=$services->get('mautic.lead.model.lead');
$dispatcher=$services->get('event_dispatcher');
$connection=$services->get('doctrine')->getConnection();
$events=[];
$listener=static function(LeadEvent $event)use(&$events,$connection):void{
  $events[]=['id'=>$event->getLead()->getId(),'tx'=>$connection->isTransactionActive()];
};
$dispatcher->addListener(LeadEvents::LEAD_POST_SAVE,$listener,999);
function freshLeadProbe():Lead{
  $lead=new Lead();
  $lead->setEmail('leadmodel-'.bin2hex(random_bytes(7)).'@example.invalid');
  $lead->setFirstname('Model');$lead->setLastname('Probe');
  return $lead;
}
function visibleLeadProbe(PDO $db,int $id):bool{
  $s=$db->prepare('SELECT COUNT(*) FROM leads WHERE id=?');
  $s->execute([$id]);return (int)$s->fetchColumn()===1;
}
function mappedLeadProbe(PDO $db,WorkspaceId $w,int $id):bool{
  $s=$db->prepare("SELECT COUNT(*) FROM mos_legacy_entity_map WHERE workspace_id=? AND source_external_id=?");
  $s->execute([(string)$w,(string)$id]);return (int)$s->fetchColumn()===1;
}
$pass=0;$fail=0;
function probeTest(string $name,Closure $run):void{
  global $pass,$fail;
  try{$run();echo "PASS $name\n";$pass++;}
  catch(Throwable $e){echo "FAIL $name: ".$e->getMessage()."\n";$fail++;}
}
function requireProbe(bool $ok):void{if(!$ok)throw new RuntimeException('Invariant not met.');}
$rolled=null;
probeTest('LEAD_POST_SAVE can dispatch before outer transaction commit',function()use($model,$connection,&$events,&$rolled){
  $rolled=freshLeadProbe();
  $connection->beginTransaction();
  try{
    $model->saveEntity($rolled);
    requireProbe($rolled->getId()>0);
    requireProbe(count($events)>0 && end($events)['id']===$rolled->getId());
    requireProbe(end($events)['tx']===true);
  }finally{if($connection->isTransactionActive())$connection->rollBack();}
});
probeTest('rolled-back LeadModel Contact is invisible to independent source scanner',function()use($rolled,$db,$scan,$w){
  requireProbe(!visibleLeadProbe($db,$rolled->getId()));
  for($i=0;$i<3;$i++)$scan->scanOnce($w,1000);
  requireProbe(!mappedLeadProbe($db,$w,$rolled->getId()));
});
probeTest('committed LeadModel Contact eventually enters atomic MOS pipeline',function()use($model,$connection,$db,$scan,$w){
  $lead=freshLeadProbe();$connection->beginTransaction();
  try{$model->saveEntity($lead);$connection->commit();}
  catch(Throwable $e){if($connection->isTransactionActive())$connection->rollBack();throw $e;}
  requireProbe(visibleLeadProbe($db,$lead->getId()));
  for($i=0;$i<6&&!mappedLeadProbe($db,$w,$lead->getId());$i++)$scan->scanOnce($w,1000);
  requireProbe(mappedLeadProbe($db,$w,$lead->getId()));
});
$dispatcher->removeListener(LeadEvents::LEAD_POST_SAVE,$listener);
$kernel->shutdown();
echo "C4-05B LEADMODEL SUMMARY $pass passed, $fail failed\n";
exit($fail===0?0:1);