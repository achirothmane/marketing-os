<?php
declare(strict_types=1);
spl_autoload_register(static function(string $class): void {
    $prefix='MarketingOS\\Contracts\\';
    if(!str_starts_with($class,$prefix)) return;
    $path=dirname(__DIR__).'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($path)) require $path;
});
use MarketingOS\Contracts\Id\UuidV7;
use MarketingOS\Contracts\Id\PersonId;
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Contracts\Id\EventId;
use MarketingOS\Contracts\Id\EvidenceId;
use MarketingOS\Contracts\Entity\EntityRef;
use MarketingOS\Contracts\Actor\ActorType;
use MarketingOS\Contracts\Actor\ActorRef;
use MarketingOS\Contracts\Time\SystemClock;
use MarketingOS\Contracts\Decision\KnowledgeState;
use MarketingOS\Contracts\Evidence\EvidenceRef;
use MarketingOS\Contracts\Event\DomainEvent;
use MarketingOS\Contracts\Money\Money;
$pass=0;$fail=0;
function check(string $name,Closure $fn): void{
    global $pass,$fail;
    try{$fn();echo "PASS {$name}\n";$pass++;}catch(Throwable $e){echo "FAIL {$name}: {$e->getMessage()}\n";$fail++;}
}
function expect(bool $ok): void {if(!$ok)throw new RuntimeException('Assertion failed');}
function throws(Closure $fn,string $type=InvalidArgumentException::class):void{
    try{$fn();}catch(Throwable $e){if($e instanceof $type)return;throw new RuntimeException('Wrong exception '.get_class($e));}
    throw new RuntimeException('Expected '.$type);
}
check('UUIDv7 variant, roundtrip',function(){
    $u=UuidV7::generate();
    expect((bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',(string)$u));
    expect($u->equals(UuidV7::fromString(strtoupper((string)$u))));
});
check('UUIDv7 no duplicates in sample',function(){
    $seen=[];for($i=0;$i<1000;$i++)$seen[(string)UuidV7::generate()]=true;expect(count($seen)===1000);
});
check('UUIDv7 rejects v4 and garbage',function(){
    throws(fn()=>UuidV7::fromString('550e8400-e29b-41d4-a716-446655440000'));
    throws(fn()=>UuidV7::fromString('abc'));
});
check('typed Person/Workspace IDs remain distinct',function(){
    $id=UuidV7::generate();
    expect((string)new PersonId($id)===(string)new WorkspaceId($id));
    expect(!(new PersonId($id) instanceof WorkspaceId));
});
check('EntityRef rejects invalid semantic type',function(){
    $id=UuidV7::generate();expect((new EntityRef('identity.person',$id))->type==='identity.person');
    throws(fn()=>new EntityRef('Person X',$id));
});
check('UTC clock',function(){expect((new SystemClock())->now()->getOffset()===0);});
check('ActorRef requires ID',function(){throws(fn()=>new ActorRef(ActorType::HUMAN,' '));});
check('knowledge UNKNOWN is not policy ALLOW',function(){
    expect(KnowledgeState::UNKNOWN->value==='UNKNOWN');
    expect(!in_array('ALLOW',array_column(KnowledgeState::cases(),'value'),true));
});
check('Evidence sha256 checks',function(){
    $now=(new SystemClock())->now();
    $e=new EvidenceRef(EvidenceId::generate(),'snapshot','mautic','mautic://contact/312',hash('sha256','bytes'),$now);
    expect(strlen($e->sha256)===64);
    throws(fn()=>new EvidenceRef(EvidenceId::generate(),'snapshot','mautic','mautic://contact/312','bad',$now));
});
check('DomainEvent causality and version fields',function(){
    $now=(new SystemClock())->now();$corr=UuidV7::generate();
    $e=new DomainEvent(EventId::generate(),'identity.person.imported.v1',1,WorkspaceId::generate(),new EntityRef('identity.person',UuidV7::generate()),1,$now,$now,new ActorRef(ActorType::SERVICE,'bridge'),$corr,null,[]);
    expect($e->schemaVersion===1&&$e->correlationId->equals($corr));
});
check('DomainEvent schema mismatch rejected',function(){
    $now=(new SystemClock())->now();
    throws(fn()=>new DomainEvent(EventId::generate(),'identity.person.imported.v2',1,WorkspaceId::generate(),new EntityRef('identity.person',UuidV7::generate()),1,$now,$now,new ActorRef(ActorType::SYSTEM,'test'),UuidV7::generate(),null,[]));
});
check('Money only decimal strings, no float',function(){
    expect((new Money('-20.50','USD'))->amount==='-20.50');
    throws(fn()=>new Money('1e2','USD'));
    throws(fn()=>new Money('1.999999999','USD'));
    throws(fn()=>new Money('10','usd'));
    throws(fn()=>new Money(1.2,'USD'),TypeError::class);
});
check('Money different currencies rejected',function(){
    throws(fn()=>(new Money('100','USD'))->assertSameCurrency(new Money('100','MAD')));
});
echo "SUMMARY {$pass} passed, {$fail} failed\n";
exit($fail===0?0:1);
