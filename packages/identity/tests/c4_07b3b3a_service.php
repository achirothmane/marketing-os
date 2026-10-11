<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Persistence\PdoSchemaMigrator;
use MarketingOS\Identity\Persistence\PdoPersonStore;

$db=mos_test_connection();
(new PdoSchemaMigrator($db))->migrate();
$a=WorkspaceId::generate();$b=WorkspaceId::generate();
$store=new PdoPersonStore($db);
$store->createWorkspace($a);$store->createWorkspace($b);
$folder=sys_get_temp_dir().'/mos-service-'.bin2hex(random_bytes(8));
if(!mkdir($folder,0700,true))throw new RuntimeException('Private directory create failed');
chmod($folder,0700);
$secrets=[
  'mos_read_db_password'=>(string)getenv('MOS_TEST_DB_PASSWORD'),
  'mos_write_db_password'=>(string)getenv('MOS_TEST_DB_PASSWORD'),
  'mos_snapshot_hmac_key'=>str_repeat('H',40),
  'mos_dlq_key_b64'=>base64_encode(str_repeat('Q',32)),
];
foreach($secrets as $name=>$value){
    file_put_contents($folder.'/'.$name,$value."\n",LOCK_EX);
    chmod($folder.'/'.$name,0600);
}
$pass=0;$fail=0;
function serviceAssert(bool $x):void {if(!$x)throw new RuntimeException('assertion');}
function serviceTest(string $name,Closure $f):void {
    global $pass,$fail;
    try{$f();echo "PASS $name\n";$pass++;}
    catch(Throwable $e){echo "FAIL $name: ".get_class($e)." ".$e->getMessage()."\n";$fail++;}
}
function envService(array $changes=[]):array {
    global $folder;
    $env=getenv();
    foreach(array_keys($env) as $key){
        if($key==='MOS_SNAPSHOT_HMAC_KEY'||$key==='MOS_DLQ_KEY_B64'||
           preg_match('/^MOS_RUNTIME_(READ|WRITE|MIGRATE)_DB_PASSWORD$/D',$key))unset($env[$key]);
    }
    return array_merge($env,[
        'CREDENTIALS_DIRECTORY'=>$folder,
        'MOS_SERVICE_ENABLED'=>'1',
        'MOS_PREFLIGHT_ENABLED'=>'1',
        'MOS_PIPELINE_ENABLED'=>'1',
        'MOS_BRIDGE_MODE'=>'SHADOW',
        'MOS_SCHEDULED_SHADOW_ENABLED'=>'1',
        'MOS_SUPERVISOR_ENABLED'=>'1',
        'MOS_QUEUE_ENABLED'=>'1',
        'MOS_QUEUE_MODE'=>'SHADOW',
        'MOS_DLQ_ACTIVE_KEY_ID'=>'ci-shadow-v1'
    ],$changes);
}
function execService(WorkspaceId $workspace,array $changes=[]):array {
    $proc=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/run_credentialed_shadow_cycle.php',(string)$workspace],
      [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,envService($changes));
    if(!is_resource($proc))throw new RuntimeException('Subprocess not started');
    fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1]);
    $stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($proc),trim($stdout),trim($stderr)];
}
function tableCountService(PDO $db,string $table,WorkspaceId $workspace):int {
    if(!in_array($table,['mos_person','mos_domain_event','mos_identity_projection','mos_messenger_wire_quarantine'],true)){
        throw new InvalidArgumentException('Bad test table');
    }
    $stmt=$db->prepare("SELECT COUNT(*) FROM $table WHERE workspace_id=?");
    $stmt->execute([(string)$workspace]);
    return (int)$stmt->fetchColumn();
}
try {
    serviceTest('service disabled by default with no worker effect',function()use($a,$db){
        [$code,$out,$err]=execService($a,['MOS_SERVICE_ENABLED'=>'0']);
        serviceAssert($code===2 && $out==='' && str_contains($err,'SERVICE_DISABLED'));
        serviceAssert(tableCountService($db,'mos_person',$a)===0);
    });
    serviceTest('secret environment collision refuses protected credential loader',function()use($a){
        [$code,$out,$err]=execService($a,['MOS_SNAPSHOT_HMAC_KEY'=>'unsafe-variable']);
        serviceAssert($code===2&&$out===''&&str_contains($err,'CREDENTIAL_ENV_COLLISION'));
    });
    serviceTest('missing READ password file blocks before DB or child',function()use($a,$folder){
        rename($folder.'/mos_read_db_password',$folder.'/missing-read-key');
        try {
            [$code,$out,$err]=execService($a);
            serviceAssert($code===2&&$out===''&&str_contains($err,'CREDENTIAL_FILE_UNSAFE'));
        } finally {
            rename($folder.'/missing-read-key',$folder.'/mos_read_db_password');
        }
    });
    serviceTest('world-readable secret file is refused even with otherwise valid settings',function()use($a,$folder){
        chmod($folder.'/mos_read_db_password',0644);
        try {
            [$code,$out,$err]=execService($a);
            serviceAssert($code===2&&$out===''&&str_contains($err,'CREDENTIAL_FILE_UNSAFE'));
        } finally {chmod($folder.'/mos_read_db_password',0600);}
    });
    serviceTest('symlinked secret is refused, not followed',function()use($a,$folder){
        rename($folder.'/mos_dlq_key_b64',$folder.'/secure-key');
        symlink($folder.'/secure-key',$folder.'/mos_dlq_key_b64');
        try {
            [$code,$out,$err]=execService($a);
            serviceAssert($code===2&&$out===''&&str_contains($err,'CREDENTIAL_FILE_UNSAFE'));
        } finally {
            unlink($folder.'/mos_dlq_key_b64');
            rename($folder.'/secure-key',$folder.'/mos_dlq_key_b64');
        }
    });
    serviceTest('incomplete independent opt-in blocks even with secrets',function()use($a){
        [$code,$out,$err]=execService($a,['MOS_QUEUE_ENABLED'=>'0']);
        serviceAssert($code===2&&$out===''&&str_contains($err,'SERVICE_DISABLED'));
    });
    serviceTest('first credentialed real Mautic SHADOW cycle reaches durable Inbox',function()use($a,$db){
        $mail='c4-service-'.bin2hex(random_bytes(8)).'@example.invalid';
        $st=$db->prepare("INSERT INTO leads(email,firstname,lastname,date_added,is_published,points)
            VALUES(?,?,?,UTC_TIMESTAMP(),1,0)");
        $st->execute([$mail,'ServiceCycle','Only']);
        [$code,$out,$err]=execService($a);
        serviceAssert($code===0&&$err==='');
        $r=json_decode($out,true,20,JSON_THROW_ON_ERROR);
        serviceAssert($r['decision']==='LOCAL_SHADOW_CYCLE_PASS'&&$r['phase']==='AFTER');
        serviceAssert($r['queue']['processed']>=1 && $r['source']['created']>=1);
        serviceAssert(tableCountService($db,'mos_person',$a)>=1);
        serviceAssert(tableCountService($db,'mos_identity_projection',$a)===tableCountService($db,'mos_person',$a));
        serviceAssert(!str_contains($out,$mail)&&!str_contains($out,str_repeat('H',40)));
    });
    serviceTest('repeat credentialed cycle preserves idempotent Person and projection',function()use($a,$db){
        $before=tableCountService($db,'mos_person',$a);
        [$code,$out,$err]=execService($a);
        serviceAssert($code===0&&$err==='');
        $r=json_decode($out,true,20,JSON_THROW_ON_ERROR);
        serviceAssert($r['source']['created']===0);
        serviceAssert(tableCountService($db,'mos_person',$a)===$before);
    });
    serviceTest('database advisory lock refuses concurrent same-Workspace cycle',function()use($a){
        $db=mos_test_connection();
        $name='mos_service_'.substr(hash('sha256',(string)$a),0,37);
        $st=$db->prepare('SELECT GET_LOCK(?,0)');$st->execute([$name]);
        serviceAssert((int)$st->fetchColumn()===1);
        try {
            [$code,$out,$err]=execService($a);
            serviceAssert($code===2&&$out===''&&str_contains($err,'SERVICE_ALREADY_RUNNING'));
        } finally { $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]); }
    });
    serviceTest('corrupt queue wire is encrypted and forces AFTER human review',function()use($a,$db){
        $invalid='bad-wire-lina@example.invalid';
        $s=$db->prepare("INSERT INTO mos_messenger_messages
           (queue_name,body,headers,created_at,available_at)
           VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $s->execute(['mos_identity_'.(string)$a,$invalid,'{}']);
        [$code,$out,$err]=execService($a);
        serviceAssert($code===3&&$err==='');
        $r=json_decode($out,true,20,JSON_THROW_ON_ERROR);
        serviceAssert($r['decision']==='REVIEW_REQUIRED'&&$r['phase']==='AFTER');
        serviceAssert(in_array('WIRE_QUARANTINE_REVIEW_REQUIRED',$r['reasons'],true));
        serviceAssert(tableCountService($db,'mos_messenger_wire_quarantine',$a)===1);
        serviceAssert(!str_contains($out,$invalid));
    });
    serviceTest('existing encrypted quarantine blocks BEFORE running another child',function()use($a,$db){
        $before=tableCountService($db,'mos_domain_event',$a);
        [$code,$out,$err]=execService($a);
        serviceAssert($code===3&&$err==='');
        $r=json_decode($out,true,20,JSON_THROW_ON_ERROR);
        serviceAssert($r['phase']==='BEFORE');
        serviceAssert(tableCountService($db,'mos_domain_event',$a)===$before);
    });
    serviceTest('another Workspace is not blocked by first Workspace quarantine',function()use($b,$db){
        [$code,$out,$err]=execService($b);
        serviceAssert($code===0&&$err==='');
        $r=json_decode($out,true,20,JSON_THROW_ON_ERROR);
        serviceAssert($r['workspace_id']===(string)$b&&$r['decision']==='LOCAL_SHADOW_CYCLE_PASS');
        serviceAssert(tableCountService($db,'mos_messenger_wire_quarantine',$b)===0);
    });
} finally {
    foreach(array_keys($secrets) as $name)@unlink($folder.'/'.$name);
    @rmdir($folder);
}
echo "C4-07B3B3A SERVICE $pass passed, $fail failed\n";
exit($fail===0?0:1);
