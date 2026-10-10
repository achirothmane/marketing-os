<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Persistence\PdoPersonStore;
use MarketingOS\Identity\Persistence\PdoSchemaMigrator;
use MarketingOS\Identity\Transport\MosEncryptedWireQuarantine;
use MarketingOS\Identity\Transport\MosMessengerJsonSerializer;
use MarketingOS\Identity\Transport\MosWireQuarantineKeyManager;

$db=mos_test_connection();
(new PdoSchemaMigrator($db))->migrate();
$a=WorkspaceId::generate();$b=WorkspaceId::generate();
(new PdoPersonStore($db))->createWorkspace($a);
(new PdoPersonStore($db))->createWorkspace($b);
$old=str_repeat('a',32);$new=str_repeat('b',32);
$manager=new MosWireQuarantineKeyManager($db);
$codec=new MosMessengerJsonSerializer();
$archiveA=new MosEncryptedWireQuarantine($db,$codec,$old,'legacy-test-v1');
$archiveB=new MosEncryptedWireQuarantine($db,$codec,$old,'legacy-test-v1');
$rowsA=[];$rowB=0;
$pass=0;$fail=0;
function checkKeys(string $title,Closure $f):void{
    global $pass,$fail;
    try{$f();echo "PASS $title\n";$pass++;}
    catch(Throwable $e){echo "FAIL $title: ".$e->getMessage()."\n";$fail++;}
}
function assertKeys(bool $yes):void{if(!$yes)throw new RuntimeException('Assertion failed');}
function seedInvalid(PDO $db,WorkspaceId $workspace,string $data):int{
    $q=$db->prepare("INSERT INTO mos_messenger_messages
      (queue_name,body,headers,created_at,available_at)
      VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $q->execute(['mos_identity_'.(string)$workspace,$data,'{}']);
    return (int)$db->lastInsertId();
}
function getArchive(PDO $db,WorkspaceId $w,int $id):array{
    $q=$db->prepare('SELECT * FROM mos_messenger_wire_quarantine WHERE workspace_id=? AND source_message_id=?');
    $q->execute([(string)$w,$id]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if($row===false)throw new RuntimeException('Archive record missing.');
    return $row;
}
function runKeyCli(WorkspaceId $w,array $args,array $env=[]):array{
    $proc=proc_open(array_merge([PHP_BINARY,dirname(__DIR__).'/bin/dlq_key_maintenance.php',(string)$w],$args),
       [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,
       array_merge(getenv(),$env));
    if(!is_resource($proc))throw new RuntimeException('Cannot run key CLI');
    fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($proc),trim($out),trim($err)];
}
function keyEnv():array{
    return [
       'MOS_DLQ_MAINTENANCE_ENABLED'=>'1',
       'MOS_QUEUE_MODE'=>'SHADOW',
       'MOS_DLQ_ROTATE_APPROVED'=>'0',
       'MOS_DLQ_OLD_KEY_B64'=>base64_encode(str_repeat('a',32)),
       'MOS_DLQ_NEW_KEY_B64'=>base64_encode(str_repeat('b',32))
    ];
}
checkKeys('schema 006 exists and maintenance does not mutate migration count',function()use($db){
    $count=(int)$db->query('SELECT COUNT(*) FROM mos_schema_migration')->fetchColumn();
    assertKeys($count===6);
});
checkKeys('archive three malformed sources in tenant A and one in B with legacy key',function()use($db,$a,$b,$archiveA,$archiveB,&$rowsA,&$rowB){
    for($i=0;$i<3;$i++)$rowsA[]=seedInvalid($db,$a,'bad json '.$i.' alice@example.invalid');
    $rowB=seedInvalid($db,$b,'bad json bob@example.invalid');
    $result=$archiveA->scan($a,20);
    assertKeys($result['quarantined']===3);
    $result=$archiveB->scan($b,20);
    assertKeys($result['quarantined']===1);
    $q=$db->query('SELECT COUNT(*) FROM mos_messenger_messages');
    assertKeys((int)$q->fetchColumn()===0);
});
checkKeys('bounded verification paginates and authenticates unmodified legacy archives',function()use($manager,$a,$old,$rowsA){
    $first=$manager->verify($a,'legacy-test-v1',$old,1);
    assertKeys($first['verified']===1&&$first['has_more']&&$first['next_cursor']===$rowsA[0]);
    $next=$manager->verify($a,'legacy-test-v1',$old,2,$first['next_cursor']);
    assertKeys($next['verified']===2&&!$next['has_more']&&$next['next_cursor']===$rowsA[2]);
});
checkKeys('wrong decryption key fails with ciphertext unchanged',function()use($manager,$a,$db,$rowsA){
    $original=getArchive($db,$a,$rowsA[0])['sealed_payload'];
    $blocked=false;
    try{$manager->verify($a,'legacy-test-v1',str_repeat('z',32));}
    catch(RuntimeException $e){$blocked=$e->getMessage()==='ARCHIVE_AUTHENTICATION_FAILED';}
    assertKeys($blocked&&getArchive($db,$a,$rowsA[0])['sealed_payload']===$original);
});
checkKeys('one-row rotation commits new authenticated ciphertext and leaves rest old',function()use($manager,$a,$old,$new,$db,$rowsA){
    $original=getArchive($db,$a,$rowsA[0]);
    $r=$manager->rotate($a,'legacy-test-v1',$old,'modern-v2',$new,1);
    assertKeys($r['rotated']===1&&$r['remaining_old_key']===2);
    $updated=getArchive($db,$a,$rowsA[0]);
    assertKeys($updated['key_id']==='modern-v2'&&$updated['source_message_id']===$original['source_message_id']);
    assertKeys($updated['reason_code']===$original['reason_code']);
    assertKeys($updated['sealed_payload']!==$original['sealed_payload']);
    assertKeys($updated['payload_sha256']===hash('sha256',$updated['sealed_payload']));
    $aad=(string)$a.'|'.$updated['queue_name'].'|'.$rowsA[0];
    $oldRaw=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
        $original['sealed_payload'],$aad,$original['sealed_nonce'],$old);
    $newRaw=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
        $updated['sealed_payload'],$aad,$updated['sealed_nonce'],$new);
    assertKeys(is_string($oldRaw)&&hash_equals($oldRaw,(string)$newRaw));
    assertKeys(sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
        $updated['sealed_payload'],$aad,$updated['sealed_nonce'],$old)===false);
});
checkKeys('resumed rotation processes remaining archives, then verifies all new records',function()use($manager,$a,$old,$new){
    $remaining=$manager->rotate($a,'legacy-test-v1',$old,'modern-v2',$new,2);
    assertKeys($remaining['rotated']===2&&$remaining['remaining_old_key']===0);
    $verified=$manager->verify($a,'modern-v2',$new,100);
    assertKeys($verified['verified']===3&&!$verified['has_more']);
    assertKeys($manager->verify($a,'legacy-test-v1',$old,100)['verified']===0);
});
checkKeys('other Workspace still encrypted under original key and unaffected',function()use($manager,$b,$old,$new,$db,$rowB){
    $r=getArchive($db,$b,$rowB);
    assertKeys($r['key_id']==='legacy-test-v1');
    assertKeys($manager->verify($b,'legacy-test-v1',$old)['verified']===1);
    assertKeys($manager->verify($b,'modern-v2',$new)['verified']===0);
});
checkKeys('tampered ciphertext refuses rotation and leaves archived evidence intact',function()use($db,$manager,$b,$old,$new,$rowB){
    $before=getArchive($db,$b,$rowB);
    $damaged=$before['sealed_payload'];
    $damaged[0]=chr(ord($damaged[0])^1);
    $stmt=$db->prepare("UPDATE mos_messenger_wire_quarantine SET sealed_payload=? WHERE workspace_id=? AND source_message_id=?");
    $stmt->execute([$damaged,(string)$b,$rowB]);
    $caught=false;
    try{$manager->rotate($b,'legacy-test-v1',$old,'modern-v2',$new,100);}
    catch(RuntimeException $e){$caught=$e->getMessage()==='ARCHIVE_AUTHENTICATION_FAILED';}
    assertKeys($caught);
    $after=getArchive($db,$b,$rowB);
    assertKeys($after['key_id']==='legacy-test-v1'&&$after['sealed_payload']===$damaged);
    $stmt->execute([$before['sealed_payload'],(string)$b,$rowB]); // disposable test restoration
});
checkKeys('rotation rejects invalid limits, same key ID and identical key material',function()use($manager,$b,$old){
    foreach([
       ['legacy-test-v1',$old,'legacy-test-v1',str_repeat('b',32),1],
       ['legacy-test-v1',$old,'modern-v2',$old,1],
       ['legacy-test-v1',$old,'modern-v2',str_repeat('b',32),101],
    ] as $case){
        $blocked=false;try{$manager->rotate($b,...$case);}catch(InvalidArgumentException $e){$blocked=true;}
        assertKeys($blocked);
    }
});
checkKeys('read-only inventory reports safe key/reason counters, never PII',function()use($manager,$a,$b){
    $v=$manager->inventory($a);
    assertKeys($v['total']===3&&$v['key_counts']['modern-v2']===3);
    assertKeys(!str_contains(json_encode($v,JSON_THROW_ON_ERROR),'@example.invalid'));
    $other=$manager->inventory($b);
    assertKeys($other['total']===1&&$other['key_counts']['legacy-test-v1']===1);
});
checkKeys('key management CLI OFF by default and ROTATE demands separate approval',function()use($a){
    [$exit,$out,$err]=runKeyCli($a,['INVENTORY','5'],
       array_merge(keyEnv(),['MOS_DLQ_MAINTENANCE_ENABLED'=>'0']));
    assertKeys($exit===2&&$out===''&&str_contains($err,'DISABLED'));
    [$exit,$out,$err]=runKeyCli($a,['ROTATE','modern-v2','modern-v3','5'],keyEnv());
    assertKeys($exit===2&&str_contains($err,'ROTATION_NOT_APPROVED'));
});
checkKeys('CLI verifies a bounded page and returns no decrypted data',function()use($a){
    [$exit,$out,$err]=runKeyCli($a,['VERIFY','modern-v2','2','0'],
      array_merge(keyEnv(),['MOS_DLQ_OLD_KEY_B64'=>base64_encode(str_repeat('b',32))]));
    assertKeys($exit===0&&$err==='');
    $v=json_decode($out,true,16,JSON_THROW_ON_ERROR);
    assertKeys($v['result']['verified']===2&&$v['result']['has_more']);
    assertKeys(!str_contains($out,'alice@example.invalid'));
});
checkKeys('advisory wire scanner lock prevents concurrent key maintenance',function()use($a){
    $other=mos_test_connection();
    $queue='mos_identity_'.(string)$a;
    $lock='mos_worker_'.substr(hash('sha256',$queue.'_WIRE_SCAN'),0,36);
    $q=$other->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);
    assertKeys((int)$q->fetchColumn()===1);
    try{
        [$exit,,$err]=runKeyCli($a,['INVENTORY','1'],keyEnv());
        assertKeys($exit===2&&str_contains($err,'DLQ_MAINTENANCE_BUSY'));
    }finally{$other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
});
checkKeys('new active key ID appears on separately scanned records',function()use($db,$a,$new,$manager){
    $id=seedInvalid($db,$a,'new key content dave@example.invalid');
    $env=[
      'MOS_QUEUE_ENABLED'=>'1','MOS_QUEUE_MODE'=>'SHADOW',
      'MOS_DLQ_KEY_B64'=>base64_encode($new),'MOS_DLQ_ACTIVE_KEY_ID'=>'modern-v2'
    ];
    $p=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/messenger_shadow_worker.php',(string)$a,'WIRE_SCAN','10'],
       [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),$env));
    if(!is_resource($p))throw new RuntimeException('Cannot spawn wire scan');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    $code=proc_close($p);
    assertKeys($code===0&&$err==='');
    $arch=getArchive($db,$a,$id);
    assertKeys($arch['key_id']==='modern-v2'&&$manager->verify($a,'modern-v2',$new)['verified']===4);
    assertKeys(!str_contains($out,'dave@example.invalid'));
});
echo "C4-07B3A SUMMARY $pass passed, $fail failed\n";
exit($fail===0?0:1);
