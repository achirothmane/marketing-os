<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Contracts\Id\EventId;
use MarketingOS\Contracts\Id\EvidenceId;
use MarketingOS\Identity\Persistence\PdoSchemaMigrator;
use MarketingOS\Identity\Persistence\PdoPersonStore;
use MarketingOS\Identity\Persistence\PdoCanonicalContactImporter;
use MarketingOS\Identity\Persistence\PdoInboxConsumer;
use MarketingOS\Identity\Persistence\PdoOutboxPublisher;

$db=mos_test_connection();
$importer=new PdoCanonicalContactImporter($db);
$publisher=new PdoOutboxPublisher($db);
$inbox=new PdoInboxConsumer($db);
$store=new PdoPersonStore($db);
$w=WorkspaceId::generate();
$pass=0;$fail=0;
function c404(string $name,Closure $f):void{
    global $pass,$fail;
    try{$f();echo "PASS ".$name."\n";$pass++;}
    catch(Throwable $e){echo "FAIL ".$name.": ".$e->getMessage()."\n";$fail++;}
}
function assert404(bool $condition):void{
    if(!$condition)throw new RuntimeException('Assertion failed.');
}
function count404(PDO $db,string $table):int{
    if(!in_array($table,['mos_person','mos_legacy_entity_map','mos_evidence','mos_domain_event','mos_outbox','mos_inbox','mos_test_projection'],true))
        throw new InvalidArgumentException('Unsafe test table.');
    return (int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
}
function digest404(int $contactId):string{
    return hash('sha256',"fixture:mautic-contact:$contactId");
}
function totals404(PDO $db):array {
    return array_map(fn($name)=>count404($db,$name),
        ['mos_person','mos_legacy_entity_map','mos_evidence','mos_domain_event','mos_outbox']);
}
c404('migrations 001, 002 and 003 can replay with immutable checksums',function()use($db){
    $m=new PdoSchemaMigrator($db);
    $m->migrate();$m->migrate();
    $q=$db->query("SELECT COUNT(*) FROM mos_schema_migration")->fetchColumn();
    assert404((int)$q===3);
});
c404('new isolated workspace',function()use($store,$w){
    $store->createWorkspace($w);
});
$main=null;
c404('one transaction commits Person/Map/Evidence/Event/Outbox',function()use($importer,$db,$w,&$main){
    $before=totals404($db);
    $main=$importer->import($w,1300,digest404(1300));
    assert404($main->created && $main->eventId!==null);
    $after=totals404($db);
    foreach($after as $i=>$n)assert404($n===$before[$i]+1);
    $s=$db->prepare("SELECT d.event_type,d.payload_json,e.content_sha256,d.aggregate_id,d.evidence_id
      FROM mos_domain_event d JOIN mos_evidence e
      ON d.workspace_id=e.workspace_id AND d.evidence_id=e.id
      WHERE d.workspace_id=? AND d.event_id=?");
    $s->execute([(string)$w,(string)$main->eventId]);
    $row=$s->fetch(PDO::FETCH_ASSOC);
    assert404($row!==false && $row['event_type']==='identity.person.imported.v1');
    assert404($row['content_sha256']===digest404(1300));
    assert404($row['aggregate_id']===(string)$main->mapping->personId);
    assert404($row['evidence_id']===(string)$main->mapping->evidenceId);
    $payload=json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR);
    assert404($payload['legacy_id']==='1300' && !array_key_exists('email',$payload));
});
c404('replay preserves event and original evidence without new rows',function()use($importer,$db,$w,&$main){
    $before=totals404($db);
    $again=$importer->import($w,1300,digest404(1300));
    assert404(!$again->created && $again->eventId===null);
    assert404($again->mapping->personId->equals($main->mapping->personId));
    assert404($again->mapping->evidenceId->equals($main->mapping->evidenceId));
    assert404($before===totals404($db));
});
c404('inject five pre-commit failures: ZERO orphan records',function()use($importer,$db,$w){
    foreach(['after_person','after_evidence','after_mapping','after_event','after_outbox'] as $i=>$step){
        $before=totals404($db);$thrown=false;
        try {
            $importer->import($w,1400+$i,digest404(1400+$i),static function(string $point)use($step):void{
                if($point===$step)throw new RuntimeException('INJECTED_CRASH_'.$step);
            });
        }catch(RuntimeException $e){$thrown=str_starts_with($e->getMessage(),'INJECTED_CRASH_');}
        assert404($thrown && totals404($db)===$before);
    }
});
c404('same contact in different workspace gives another Person',function()use($importer,$store,$w,$main){
    $another=WorkspaceId::generate();
    $store->createWorkspace($another);
    $other=$importer->import($another,1300,digest404(1300));
    assert404(!$other->mapping->personId->equals($main->mapping->personId));
});
c404('different contacts remain different Persons despite equal digest',function()use($importer,$w){
    $digest=digest404(1300);
    $one=$importer->import($w,1500,$digest);
    $two=$importer->import($w,1501,$digest);
    assert404(!$one->mapping->personId->equals($two->mapping->personId));
});
c404('invalid evidence digest refused without side effects',function()use($importer,$db,$w){
    $before=totals404($db);
    $caught=false;
    try{$importer->import($w,1502,'not-sha256');}catch(InvalidArgumentException $e){$caught=true;}
    assert404($caught && totals404($db)===$before);
});
c404('two worker race: one Person, Evidence, Event and Outbox',function()use($db,$w){
    $mark=sys_get_temp_dir().'/mos-c404-'.bin2hex(random_bytes(6));
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/import_race_worker.php').' '.
       escapeshellarg((string)$w).' 999901 '.escapeshellarg($mark);
    $procs=[];
    for($i=0;$i<2;$i++){
        $p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($p))throw new RuntimeException('Race worker spawn failed.');
        fclose($pipes[0]);$procs[]=[$p,$pipes];
    }
    touch($mark);
    try {
        $ids=[];
        foreach($procs as [$p,$pipes]){
            $out=trim(stream_get_contents($pipes[1]));
            $err=trim(stream_get_contents($pipes[2]));
            fclose($pipes[1]);fclose($pipes[2]);
            $exit=proc_close($p);
            if($exit!==0)throw new RuntimeException('Race worker failed: '.$err);
            $ids[]=$out;
        }
        assert404(count(array_unique($ids))===1);
        $sql="SELECT COUNT(*) FROM mos_domain_event
          WHERE workspace_id=? AND event_type='identity.person.imported.v1'
          AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.legacy_id'))='999901'";
        $q=$db->prepare($sql);$q->execute([(string)$w]);
        assert404((int)$q->fetchColumn()===1);
        $q=$db->prepare("SELECT COUNT(*) FROM mos_evidence WHERE workspace_id=? AND source_locator='mautic://contact/999901'");
        $q->execute([(string)$w]);assert404((int)$q->fetchColumn()===1);
    } finally {@unlink($mark);}
});
c404('inbox insert and projection roll back when handler throws',function()use($db,$inbox,$w,&$main){
    $db->exec("CREATE TABLE IF NOT EXISTS mos_test_projection (
      workspace_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      PRIMARY KEY(workspace_id,event_id)
    ) ENGINE=InnoDB");
    $before=count404($db,'mos_inbox');
    $thrown=false;
    try{$inbox->consume($w,$main->eventId,'test_projection',static function(array $ev,PDO $conn):void{
        $s=$conn->prepare("INSERT INTO mos_test_projection VALUES(?,?)");
        $s->execute([$ev['workspace_id'],$ev['event_id']]);
        throw new RuntimeException('simulated projection failure');
    });}catch(RuntimeException $e){$thrown=true;}
    assert404($thrown && count404($db,'mos_inbox')===$before && count404($db,'mos_test_projection')===0);
});
c404('inbox rejects cross-workspace event reference',function()use($inbox,&$main){
    $wrong=WorkspaceId::generate();
    $caught=false;
    try{$inbox->consume($wrong,$main->eventId,'test_projection',static function(){});}
    catch(InvalidArgumentException $e){$caught=true;}
    assert404($caught);
});
c404('outbox keeps pending event after commit and before publish',function()use($db,$main,$w){
    $s=$db->prepare("SELECT published_at,attempts FROM mos_outbox WHERE workspace_id=? AND event_id=?");
    $s->execute([(string)$w,(string)$main->eventId]);$row=$s->fetch(PDO::FETCH_ASSOC);
    assert404($row['published_at']===null && (int)$row['attempts']===0);
});
c404('lost ACK after committed consumer effect: outbox retries, inbox dedups',function()use($db,$inbox,$publisher,$w,&$main){
    $applied=0;$delivered=0;
    $consumer=function(array $msg)use($inbox,&$applied,&$delivered):void{
        $delivered++;
        $inbox->consume(
          WorkspaceId::fromString($msg['workspace_id']),
          EventId::fromString($msg['event_id']),
          'test_projection',
          static function(array $ev,PDO $conn)use(&$applied):void{
              $s=$conn->prepare("INSERT INTO mos_test_projection VALUES(?,?)");
              $s->execute([$ev['workspace_id'],$ev['event_id']]);
              $applied++;
          });
    };
    $lost=false;
    try{
        $publisher->publishOne(function(array $message)use($consumer):void{
            $consumer($message);
            throw new RuntimeException('ack disappeared after consumer commit');
        });
    }catch(RuntimeException $e){$lost=true;}
    assert404($lost && $applied===1 && $delivered===1);
    $q=$db->prepare("SELECT published_at,attempts FROM mos_outbox WHERE workspace_id=? AND event_id=?");
    $q->execute([(string)$w,(string)$main->eventId]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    assert404($row['published_at']===null && (int)$row['attempts']===1);
    // A second attempt may redeliver, but can't duplicate business projection.
    $publisher->publishOne($consumer);
    assert404($delivered===2 && $applied===1);
    $q->execute([(string)$w,(string)$main->eventId]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    assert404($row['published_at']!==null && (int)$row['attempts']===2);
});
c404('drain other outbox events exactly once per consumer',function()use($db,$inbox,$publisher){
    $before=count404($db,'mos_test_projection');
    $count=0;
    while(($id=$publisher->publishOne(function(array $msg)use($inbox):void{
        $inbox->consume(
          WorkspaceId::fromString($msg['workspace_id']),
          EventId::fromString($msg['event_id']),
          'test_projection',
          static function(array $e,PDO $c):void{
              $s=$c->prepare("INSERT INTO mos_test_projection VALUES(?,?)");
              $s->execute([$e['workspace_id'],$e['event_id']]);
          });
    }))!==null){
        $count++;
        if($count>100)throw new RuntimeException('Publisher not draining.');
    }
    assert404($count>0 && count404($db,'mos_test_projection')===$before+$count);
    assert404((int)$db->query("SELECT COUNT(*) FROM mos_outbox WHERE published_at IS NULL")->fetchColumn()===0);
    assert404($publisher->publishOne(static function():void{throw new RuntimeException('Should never deliver');})===null);
});
echo "C4-04 SUMMARY $pass passed, $fail failed\n";
exit($fail===0?0:1);
