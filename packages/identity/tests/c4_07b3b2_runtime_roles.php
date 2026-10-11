<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require dirname(__DIR__).'/bootstrap/runtime.php';
use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Persistence\PdoSchemaMigrator;
use MarketingOS\Identity\Persistence\PdoPersonStore;

if (getenv('MOS_CI_ALLOW_RUNTIME_ROLE_GRANTS') !== '1' ||
    getenv('MOS_TEST_DSN') !== 'mysql:host=127.0.0.1;port=3306;dbname=mautic_ci;charset=utf8mb4' ||
    getenv('MOS_CI_ROOT_PASSWORD') !== 'ci-only-root-password') {
    fwrite(STDERR,"ROLE TEST DISABLED: disposable MariaDB CI only\n"); exit(2);
}
$admin=mos_test_connection();
(new PdoSchemaMigrator($admin))->migrate();
$workspace=WorkspaceId::generate();
(new PdoPersonStore($admin))->createWorkspace($workspace);
$root=new PDO((string)getenv('MOS_TEST_DSN'),'root',(string)getenv('MOS_CI_ROOT_PASSWORD'),[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false,
]);
foreach(['mos_ro_ci','mos_rw_ci'] as $name){
    $root->exec("DROP USER IF EXISTS '".$name."'@'%'");
    $root->exec("CREATE USER '".$name."'@'%' IDENTIFIED BY 'ci-fixture-only-not-a-real-secret'");
}
$readTables=['mos_workspace','mos_schema_migration','mos_outbox','mos_messenger_messages',
    'mos_messenger_failure','mos_messenger_wire_quarantine'];
foreach($readTables as $table){
    $root->exec("GRANT SELECT ON mautic_ci.".$table." TO 'mos_ro_ci'@'%'");
}
$writeTables=['mos_workspace','mos_person','mos_legacy_entity_map','mos_evidence','mos_domain_event',
    'mos_outbox','mos_inbox','mos_contact_scan_cursor','mos_source_reconciliation_case',
    'mos_source_reconciliation_observation','mos_messenger_messages','mos_identity_projection',
    'mos_messenger_failure','mos_messenger_wire_quarantine'];
foreach($writeTables as $table){
    $root->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON mautic_ci.".$table." TO 'mos_rw_ci'@'%'");
}
$root->exec("GRANT SELECT ON mautic_ci.mos_schema_migration TO 'mos_rw_ci'@'%'");
unset($root);
$dsn=(string)getenv('MOS_TEST_DSN');
$readonly=['MOS_RUNTIME_READ_DSN'=>$dsn,'MOS_RUNTIME_READ_DB_USER'=>'mos_ro_ci',
  'MOS_RUNTIME_READ_DB_PASSWORD'=>'ci-fixture-only-not-a-real-secret'];
$writer=['MOS_RUNTIME_WRITE_DSN'=>$dsn,'MOS_RUNTIME_WRITE_DB_USER'=>'mos_rw_ci',
  'MOS_RUNTIME_WRITE_DB_PASSWORD'=>'ci-fixture-only-not-a-real-secret'];
function withRoleEnv(array $vars,Closure $fn):void {
    $orig=[];
    foreach($vars as $name=>$value){
        $orig[$name]=getenv($name);
        $value===null?putenv($name):putenv($name.'='.$value);
    }
    try{$fn();}finally{
        foreach($orig as $name=>$value){$value===false?putenv($name):putenv($name.'='.$value);}
    }
}
$pass=0;$fail=0;
function assertRuntime(bool $yes):void {if(!$yes)throw new RuntimeException('assertion failed');}
function checkRuntime(string $name,Closure $f):void {
    global $pass,$fail;
    try{$f();echo "PASS $name\n";$pass++;}
    catch(Throwable $e){echo "FAIL $name: ".get_class($e)."\n";$fail++;}
}
function startRuntimeCli(string $bin,array $args,array $env):array {
    $p=proc_open(array_merge([PHP_BINARY,dirname(__DIR__).'/bin/'.$bin],$args),
      [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),$env));
    if(!is_resource($p))throw new RuntimeException('Cannot start CLI');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($p),trim($out),trim($err)];
}
checkRuntime('runtime bootstrap contains no test credential fallback',function(){
    $src=file_get_contents(dirname(__DIR__).'/bootstrap/runtime.php');
    assertRuntime(!str_contains($src,'tests/bootstrap.php')&&!str_contains($src,'MOS_TEST_'));
    foreach(glob(dirname(__DIR__).'/bin/*.php') as $path){
        $c=file_get_contents($path);
        assertRuntime(!str_contains($c,'/tests/bootstrap.php'));
        assertRuntime(!str_contains($c,'mos_test_connection')&&!str_contains($c,'MOS_TEST_DSN'));
    }
});
checkRuntime('missing READ profile rejects even with populated test fixture',function()use($readonly){
    withRoleEnv(array_fill_keys(array_keys($readonly),null),function(){
        $denied=false;try{mos_runtime_connection('READ');}catch(RuntimeException $e){
            $denied=$e->getMessage()==='RUNTIME_CREDENTIALS_MISSING';}
        assertRuntime($denied);
    });
});
checkRuntime('unsupported DSN and unexpected role fail closed',function()use($readonly){
    withRoleEnv(array_merge($readonly,['MOS_RUNTIME_READ_DSN'=>'mysql:host=127.0.0.1;port=3306;dbname=mautic_ci;charset=utf8mb4;unix_socket=/tmp/unsafe']),function(){
        $denied=false;try{mos_runtime_connection('READ');}catch(RuntimeException $e){$denied=$e->getMessage()==='RUNTIME_DSN_INVALID';}
        assertRuntime($denied);
    });
    $denied=false;try{mos_runtime_connection('OWNER');}catch(RuntimeException $e){$denied=$e->getMessage()==='RUNTIME_ROLE_INVALID';}
    assertRuntime($denied);
});
checkRuntime('READ user selects scoped rows but cannot UPDATE',function()use($readonly,$workspace){
    withRoleEnv($readonly,function()use($workspace){
        $db=mos_runtime_connection('READ');
        $q=$db->prepare('SELECT COUNT(*) FROM mos_workspace WHERE id=?');$q->execute([(string)$workspace]);
        assertRuntime((int)$q->fetchColumn()===1);
        $denied=false;try{$db->exec('UPDATE mos_workspace SET id=id');}catch(PDOException $e){$denied=true;}
        assertRuntime($denied);
    });
});
checkRuntime('WRITE user supports DML but cannot perform DDL',function()use($writer){
    withRoleEnv($writer,function(){
        $db=mos_runtime_connection('WRITE');
        $w=WorkspaceId::generate();
        $q=$db->prepare('INSERT INTO mos_workspace (id,created_at) VALUES (?,UTC_TIMESTAMP(6))');
        $q->execute([(string)$w]);
        $db->prepare('DELETE FROM mos_workspace WHERE id=?')->execute([(string)$w]);
        $denied=false;try{$db->exec('CREATE TABLE mos_forbidden_proof (id INT)');}catch(PDOException $e){$denied=true;}
        assertRuntime($denied);
    });
});
checkRuntime('preflight CLI passes with actual SELECT-only MariaDB grants',function()use($readonly,$workspace){
    [$exit,$out,$err]=startRuntimeCli('shadow_readiness_preflight.php',[(string)$workspace,'ci-v2','10'],
      array_merge($readonly,['MOS_PREFLIGHT_ENABLED'=>'1','MOS_QUEUE_MODE'=>'SHADOW','MOS_BRIDGE_MODE'=>'SHADOW',
        'MOS_DLQ_KEY_B64'=>base64_encode(str_repeat('Q',32)),
        'MOS_SNAPSHOT_HMAC_KEY'=>str_repeat('H',32)]));
    assertRuntime($exit===0&&$err==='');
    assertRuntime(json_decode($out,true,16,JSON_THROW_ON_ERROR)['decision']==='LOCAL_PREFLIGHT_PASS');
});
checkRuntime('no READ role in child is not substituted by TEST or WRITE',function()use($readonly,$workspace){
    [$exit,$out,$err]=startRuntimeCli('shadow_readiness_preflight.php',[(string)$workspace,'ci-v2','10'],
      array_merge(array_fill_keys(array_keys($readonly),''),
       ['MOS_PREFLIGHT_ENABLED'=>'1','MOS_QUEUE_MODE'=>'SHADOW','MOS_BRIDGE_MODE'=>'SHADOW',
        'MOS_DLQ_KEY_B64'=>base64_encode(str_repeat('Q',32)),
        'MOS_SNAPSHOT_HMAC_KEY'=>str_repeat('H',32)]));
    assertRuntime($exit===2&&$out===''&&str_contains($err,'PREFLIGHT_BLOCKED'));
});
checkRuntime('no WRITE role is not substituted by valid READ',function()use($readonly,$writer){
    withRoleEnv(array_merge($readonly,array_fill_keys(array_keys($writer),null)),function(){
        $denied=false;try{mos_runtime_connection('WRITE');}catch(RuntimeException $e){
            $denied=$e->getMessage()==='RUNTIME_CREDENTIALS_MISSING';}
        assertRuntime($denied);
    });
});
checkRuntime('migration CLI requires explicit approval and separate MIGRATE role',function()use($readonly,$writer){
    [$exit,$out,$err]=startRuntimeCli('migrate.php',[],array_merge($readonly,$writer,
      ['MOS_SCHEMA_MIGRATION_ENABLED'=>'0']));
    assertRuntime($exit===2&&$out===''&&str_contains($err,'MIGRATION_DISABLED'));
    [$exit,$out,$err]=startRuntimeCli('migrate.php',[],array_merge($readonly,$writer,
      ['MOS_SCHEMA_MIGRATION_ENABLED'=>'1','MOS_RUNTIME_MIGRATE_DSN'=>'',
       'MOS_RUNTIME_MIGRATE_DB_USER'=>'','MOS_RUNTIME_MIGRATE_DB_PASSWORD'=>'']));
    assertRuntime($exit===2&&$out===''&&str_contains($err,'RUNTIME_CREDENTIALS_MISSING'));
});
echo "C4-07B3B2 ROLE SEPARATION $pass passed, $fail failed\n";
exit($fail===0?0:1);
