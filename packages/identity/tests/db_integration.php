<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use MarketingOS\Identity\Persistence\PdoSchemaMigrator;
use MarketingOS\Identity\Persistence\PdoLegacyContactRegistry;
use MarketingOS\Identity\Persistence\PdoPersonStore;
use MarketingOS\Identity\PersonState;
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Contracts\Id\PersonId;
use MarketingOS\Contracts\Id\EvidenceId;

$db=mos_test_connection();
$pass=0; $fail=0;
function checkDb(string $name, Closure $fn):void {
    global $pass,$fail;
    try{$fn();echo "PASS $name\n";$pass++;}
    catch(Throwable $e){echo "FAIL $name: ".$e->getMessage()."\n";$fail++;}
}
function okDb(bool $condition):void {if(!$condition)throw new RuntimeException('Assertion failed');}
function refuses(Closure $fn,string $expected):void {
    try{$fn();}catch(Throwable $e){
        if($e instanceof $expected)return;
        throw new RuntimeException('Wrong exception: '.get_class($e).' '.$e->getMessage());
    }
    throw new RuntimeException('Expected rejection.');
}
function numRows(PDO $db,string $table):int {
    if(!in_array($table,['mos_person','mos_legacy_entity_map','mos_workspace'],true))
        throw new RuntimeException('Unsafe table argument.');
    return (int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
}
$w1=WorkspaceId::generate();$w2=WorkspaceId::generate();
$store=new PdoPersonStore($db);$registry=new PdoLegacyContactRegistry($db);
checkDb('checksum migration applies twice safely',function()use($db){
    $m=new PdoSchemaMigrator($db);$m->migrate();$m->migrate();
    $hash=(string)$db->query("SELECT checksum FROM mos_schema_migration WHERE version='001_person_legacy_mapping'")->fetchColumn();
    okDb((bool)preg_match('/^[a-f0-9]{64}$/D',$hash));
});
checkDb('workspaces created separately',function()use($store,$db,$w1,$w2){
    $store->createWorkspace($w1);$store->createWorkspace($w2);
    okDb(numRows($db,'mos_workspace')===2);
});
checkDb('contact import and repeat return same Person and evidence',function()use($store,$registry,$db,$w1){
    $e=EvidenceId::generate();
    $a=$registry->registerMauticContact($w1,312,$e);
    okDb($store->find($w1,$a->personId)?->state()===PersonState::ACTIVE);
    $b=$registry->registerMauticContact($w1,312,EvidenceId::generate());
    okDb($a->personId->equals($b->personId)&&$a->evidenceId->equals($b->evidenceId));
    okDb(numRows($db,'mos_person')===1);
});
checkDb('other Contact 891 never merges into 312',function()use($w1,$registry){
    $a=$registry->registerMauticContact($w1,312,EvidenceId::generate());
    $b=$registry->registerMauticContact($w1,891,EvidenceId::generate());
    okDb(!$a->personId->equals($b->personId));
});
checkDb('same Contact 312 in another workspace is distinct',function()use($registry,$w1,$w2){
    $a=$registry->registerMauticContact($w1,312,EvidenceId::generate());
    $b=$registry->registerMauticContact($w2,312,EvidenceId::generate());
    okDb(!$a->personId->equals($b->personId));
});
checkDb('cross workspace Person lookups and map FK protected',function()use($db,$registry,$store,$w1,$w2){
    $a=$registry->registerMauticContact($w1,312,EvidenceId::generate());
    $b=$registry->registerMauticContact($w2,312,EvidenceId::generate());
    okDb($store->find($w2,$a->personId)===null);
    $s=$db->prepare("INSERT INTO mos_legacy_entity_map (workspace_id,source_system,source_entity_type,source_external_id,person_id,evidence_id,origin,recorded_at)
      VALUES (?,'mautic','contact','9999',?,?,'TEST',UTC_TIMESTAMP(6))");
    refuses(fn()=>$s->execute([(string)$w1,(string)$b->personId,(string)EvidenceId::generate()]),PDOException::class);
});
checkDb('source key UNIQUE rejects conflicting mapping',function()use($db,$registry,$w1){
    $person=$registry->registerMauticContact($w1,312,EvidenceId::generate());
    $s=$db->prepare("INSERT INTO mos_legacy_entity_map (workspace_id,source_system,source_entity_type,source_external_id,person_id,evidence_id,origin,recorded_at)
      VALUES (?,'mautic','contact','312',?,?,'TEST',UTC_TIMESTAMP(6))");
    refuses(fn()=>$s->execute([(string)$w1,(string)$person->personId,(string)EvidenceId::generate()]),PDOException::class);
});
checkDb('partial duplicate insert rolls back tentative Person',function()use($db,$w1){
    $before=numRows($db,'mos_person');
    $db->beginTransaction();
    try{
        $person=PersonId::generate();
        $s=$db->prepare("INSERT INTO mos_person (workspace_id,id,state,version,created_at,updated_at)
           VALUES (?,?,'ACTIVE',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
        $s->execute([(string)$w1,(string)$person]);
        $d=$db->prepare("INSERT INTO mos_legacy_entity_map (workspace_id,source_system,source_entity_type,source_external_id,person_id,evidence_id,origin,recorded_at)
           VALUES (?,'mautic','contact','312',?,?,'TEST',UTC_TIMESTAMP(6))");
        refuses(fn()=>$d->execute([(string)$w1,(string)$person,(string)EvidenceId::generate()]),PDOException::class);
    } finally {$db->rollBack();}
    okDb(numRows($db,'mos_person')===$before);
});
checkDb('CAS persists state and rejects stale version',function()use($w1,$registry,$store){
    $map=$registry->registerMauticContact($w1,500,EvidenceId::generate());
    $person=$store->find($w1,$map->personId);
    $stale=$store->find($w1,$map->personId);
    $person->markErased(1);$store->saveTransition($person,1);
    $stale->markErased(1);
    refuses(fn()=>$store->saveTransition($stale,1),DomainException::class);
    okDb($store->find($w1,$map->personId)->version()===2);
});
checkDb('schema rejects bad Person state and cross-tenant references',function()use($db,$w1){
    $s=$db->prepare("INSERT INTO mos_person (workspace_id,id,state,version,created_at,updated_at)
      VALUES (?,?,'INVALID',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
    refuses(fn()=>$s->execute([(string)$w1,(string)PersonId::generate()]),PDOException::class);
    $valid=$db->prepare("INSERT INTO mos_person (workspace_id,id,state,version,created_at,updated_at)
      VALUES (?,?,'ACTIVE',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
    refuses(fn()=>$valid->execute([(string)WorkspaceId::generate(),(string)PersonId::generate()]),PDOException::class);
});
checkDb('race across two PHP workers yields one Person only',function()use($db,$w1){
    $marker=sys_get_temp_dir().'/mos-race-'.bin2hex(random_bytes(6));
    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/race_worker.php').' '.
      escapeshellarg((string)$w1).' 700001 '.escapeshellarg($marker);
    $spec=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
    $procs=[];
    for($i=0;$i<2;$i++){
        $proc=proc_open($command,$spec,$pipes);
        if(!is_resource($proc))throw new RuntimeException('Worker could not start.');
        fclose($pipes[0]);$procs[]=[$proc,$pipes];
    }
    touch($marker);
    try{
        $results=[];
        foreach($procs as [$proc,$pipes]){
            $out=trim(stream_get_contents($pipes[1]));
            $err=trim(stream_get_contents($pipes[2]));
            fclose($pipes[1]);fclose($pipes[2]);
            if(proc_close($proc)!==0)throw new RuntimeException('Worker: '.$err);
            $results[]=$out;
        }
        okDb(count(array_unique($results))===1);
        $s=$db->prepare("SELECT COUNT(*) FROM mos_legacy_entity_map WHERE workspace_id=? AND source_system='mautic' AND source_external_id='700001'");
        $s->execute([(string)$w1]);okDb((int)$s->fetchColumn()===1);
        $s=$db->prepare("SELECT COUNT(*) FROM mos_person WHERE workspace_id=?");
        $s->execute([(string)$w1]);okDb((int)$s->fetchColumn()===4);
    } finally {@unlink($marker);}
});
echo "DB SUMMARY $pass passed, $fail failed\n";
exit($fail===0?0:1);
