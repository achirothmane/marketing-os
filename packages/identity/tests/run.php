<?php
declare(strict_types=1);

spl_autoload_register(static function(string $class): void {
    $prefixes = [
        'MarketingOS\\Contracts\\' => dirname(__DIR__,2).'/contracts/src/',
        'MarketingOS\\Identity\\' => dirname(__DIR__).'/src/',
    ];
    foreach ($prefixes as $prefix=>$dir) {
        if (!str_starts_with($class,$prefix)) continue;
        $f=$dir.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if (is_file($f)) require $f;
        return;
    }
});

use MarketingOS\Contracts\Id\PersonId;
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Contracts\Id\EvidenceId;
use MarketingOS\Identity\Person;
use MarketingOS\Identity\PersonState;
use MarketingOS\Identity\LegacyEntityRef;
use MarketingOS\Identity\LegacyEntityMapping;
use MarketingOS\Identity\LegacyMappingPlanner;
use MarketingOS\Identity\MappingResolution;

$pass=0; $fail=0;
function check(string $name,Closure $fn): void {
    global $pass,$fail;
    try { $fn(); echo "PASS {$name}\n"; $pass++; }
    catch (Throwable $e) { echo "FAIL {$name}: {$e->getMessage()}\n"; $fail++; }
}
function truth(bool $ok): void { if(!$ok)throw new RuntimeException('Assertion failed'); }
function rejects(Closure $fn,string $type): void {
    try{$fn();}catch(Throwable $e){if($e instanceof $type)return;throw new RuntimeException('Expected '.$type.', got '.get_class($e));}
    throw new RuntimeException('Expected '.$type.' was not thrown.');
}
function utc(): DateTimeImmutable { return new DateTimeImmutable('2026-10-09T00:00:00+00:00'); }

check('person starts ACTIVE with aggregate version 1',function(){
    $p=Person::create(WorkspaceId::generate());
    truth($p->state()===PersonState::ACTIVE && $p->version()===1 && $p->mergedInto()===null);
});
check('person ID is not email-derived',function(){
    $w=WorkspaceId::generate();
    $a=Person::create($w);$b=Person::create($w);
    truth(!$a->id->equals($b->id));
});
check('merge changes source lifecycle, keeps target and increments version',function(){
    $w=WorkspaceId::generate();$a=Person::create($w);$b=Person::create($w);
    $a->mergeInto($b,1);
    truth($a->state()===PersonState::MERGED && $a->version()===2 && $a->mergedInto()?->equals($b->id));
    truth($b->state()===PersonState::ACTIVE);
});
check('self merge is refused',function(){
    $p=Person::create(WorkspaceId::generate());
    rejects(fn()=> $p->mergeInto($p,1),DomainException::class);
});
check('cross-workspace merge is refused',function(){
    $p=Person::create(WorkspaceId::generate());$other=Person::create(WorkspaceId::generate());
    rejects(fn()=> $p->mergeInto($other,1),DomainException::class);
});
check('stale merge version is refused without state mutation',function(){
    $w=WorkspaceId::generate();$a=Person::create($w);$b=Person::create($w);
    rejects(fn()=> $a->mergeInto($b,9),DomainException::class);
    truth($a->state()===PersonState::ACTIVE && $a->version()===1);
});
check('merging into a nonactive person is refused',function(){
    $w=WorkspaceId::generate();$a=Person::create($w);$b=Person::create($w);
    $b->markErased(1);
    rejects(fn()=> $a->mergeInto($b,1),DomainException::class);
});
check('ERASED marker is versioned, repeated action rejected',function(){
    $p=Person::create(WorkspaceId::generate());
    $p->markErased(1);truth($p->state()===PersonState::ERASED && $p->version()===2);
    rejects(fn()=> $p->markErased(2),DomainException::class);
});
check('Mautic Contact ID validates positive integer',function(){
    $w=WorkspaceId::generate();
    truth(LegacyEntityRef::mauticContact($w,312)->externalId==='312');
    rejects(fn()=>LegacyEntityRef::mauticContact($w,0),InvalidArgumentException::class);
});
check('source identity key is stable and workspace-scoped',function(){
    $w=WorkspaceId::generate();
    $a=LegacyEntityRef::mauticContact($w,312);
    $again=LegacyEntityRef::mauticContact($w,312);
    $different=LegacyEntityRef::mauticContact($w,891);
    $otherTenant=LegacyEntityRef::mauticContact(WorkspaceId::generate(),312);
    truth($a->key()===$again->key()&&$a->key()!==$different->key()&&$a->key()!==$otherTenant->key());
});
check('invalid source identifiers refused',function(){
    $w=WorkspaceId::generate();
    rejects(fn()=>new LegacyEntityRef($w,'Mautic','contact','2'),InvalidArgumentException::class);
    rejects(fn()=>new LegacyEntityRef($w,'mautic','contact',' '),InvalidArgumentException::class);
});
check('legacy mapping requires matched workspace and UTC timestamp',function(){
    $w=WorkspaceId::generate();$p=Person::create($w);
    $ref=LegacyEntityRef::mauticContact($w,312);
    $m=LegacyEntityMapping::forPerson($ref,$p,EvidenceId::generate(),utc());
    truth($m->personId->equals($p->id));
    rejects(fn()=>LegacyEntityMapping::forPerson(LegacyEntityRef::mauticContact(WorkspaceId::generate(),312),$p,EvidenceId::generate(),utc()),InvalidArgumentException::class);
    rejects(fn()=>new LegacyEntityMapping($ref,$p->id,EvidenceId::generate(),new DateTimeImmutable('2026-10-09T01:00:00+01:00')),InvalidArgumentException::class);
});
check('planner reuses existing binding without creating a new Person',function(){
    $w=WorkspaceId::generate();$source=LegacyEntityRef::mauticContact($w,312);
    $p=Person::create($w);$planner=new LegacyMappingPlanner();
    truth($planner->plan($source,null)->resolution===MappingResolution::UNBOUND);
    $saved=LegacyEntityMapping::forPerson($source,$p,EvidenceId::generate(),utc());
    $decision=$planner->plan($source,$saved);
    truth($decision->resolution===MappingResolution::REUSE_EXISTING && $decision->existingPersonId?->equals($p->id));
});
check('planner rejects irrelevant mapping and never matches on email',function(){
    $w=WorkspaceId::generate();$a=LegacyEntityRef::mauticContact($w,312);$b=LegacyEntityRef::mauticContact($w,891);
    $stored=LegacyEntityMapping::forPerson($a,Person::create($w),EvidenceId::generate(),utc());
    rejects(fn()=>(new LegacyMappingPlanner())->plan($b,$stored),InvalidArgumentException::class);
    truth((new LegacyMappingPlanner())->plan($b,null)->resolution===MappingResolution::UNBOUND);
});
echo "SUMMARY {$pass} passed, {$fail} failed\n";
exit($fail===0?0:1);
