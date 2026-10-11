<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use MarketingOS\Identity\Runtime\MosDatabaseRestoreAttestor;
use MarketingOS\Identity\Runtime\MosRestoredArchiveVerifier;
use MarketingOS\Contracts\Id\WorkspaceId;

if(getenv('MOS_CI_ALLOW_FULL_SQL_RESTORE')!=='1'||
   getenv('MOS_TEST_DSN')!=='mysql:host=127.0.0.1;port=3306;dbname=mautic_ci;charset=utf8mb4'||
   getenv('MOS_CI_ROOT_PASSWORD')!=='ci-only-root-password') {
   fwrite(STDERR,"DATABASE BACKUP TEST DISABLED\n");exit(2);
}
$source=mos_test_connection();
$restored=new PDO('mysql:host=127.0.0.1;port=3306;dbname=mautic_restore_ci;charset=utf8mb4',
    'root',(string)getenv('MOS_CI_ROOT_PASSWORD'),[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
$attestor=new MosDatabaseRestoreAttestor($source,$restored);
$passed=0;$failed=0;
function checkFullRestore(string $name,Closure $callback):void{
    global $passed,$failed;
    try{$callback();echo "PASS $name\n";$passed++;}
    catch(Throwable $e){echo "FAIL $name: ".get_class($e)."\n";$failed++;}
}
function mustFullRestore(bool $condition):void{
    if(!$condition)throw new RuntimeException('Assertion failed');
}
function restoreFails(Closure $call,string $code):void {
    $caught=false;try{$call();}catch(RuntimeException $e){$caught=$e->getMessage()===$code;}
    mustFullRestore($caught);
}
$baseline=null;
checkFullRestore('independent SQL imported database has complete table set and exact row contents',function()use($attestor,&$baseline){
    $baseline=$attestor->attest();
    mustFullRestore($baseline['decision']==='DISPOSABLE_SQL_RESTORE_DB_STATE_MATCH');
    mustFullRestore($baseline['tables']>=120&&$baseline['mos_tables']>=15);
    mustFullRestore($baseline['rows']>0&&$baseline['encrypted_archives']>0);
});
checkFullRestore('Mautic leads and MOS Inbox/Outbox state are present in recovered database',function()use($restored){
    foreach(['leads','mos_person','mos_domain_event','mos_outbox','mos_inbox',
             'mos_identity_projection','mos_messenger_wire_quarantine'] as $table) {
        $stmt=$restored->query('SELECT COUNT(*) FROM '.chr(96).$table.chr(96));
        mustFullRestore((int)$stmt->fetchColumn()>0);
    }
});
checkFullRestore('backup contains six checksum-pinned MOS migrations',function()use($restored){
    $versions=$restored->query('SELECT version,checksum FROM mos_schema_migration ORDER BY version')->fetchAll(PDO::FETCH_KEY_PAIR);
    mustFullRestore(count($versions)===6);
    foreach($versions as $v=>$checksum){
        $hash=hash_file('sha256',dirname(__DIR__).'/migrations/'.$v.'.sql');
        mustFullRestore(is_string($hash)&&hash_equals($checksum,$hash));
    }
});
checkFullRestore('archived ciphertext retains original authentication under separately supplied test key',function()use($source,$restored){
    $stmt=$source->query('SELECT workspace_id FROM mos_messenger_wire_quarantine GROUP BY workspace_id');
    $workspaces=$stmt->fetchAll(PDO::FETCH_COLUMN);
    mustFullRestore(count($workspaces)>=1);
    $proof=new MosRestoredArchiveVerifier($source,$restored);
    $sum=0;
    foreach($workspaces as $uuid){
        $cursor=0;
        do{
            $receipt=$proof->verifyPage(WorkspaceId::fromString($uuid),['manual-v1'=>str_repeat('Q',32)],100,$cursor);
            $cursor=$receipt['next_cursor'];$sum+=$receipt['verified'];
        }while($receipt['has_more']);
    }
    mustFullRestore($sum>0);
});
checkFullRestore('mutating restored MOS Person state is detected without touching original data',function()use($attestor,$restored){
    $row=$restored->query('SELECT workspace_id,id,version FROM mos_person LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    mustFullRestore(is_array($row));
    $s=$restored->prepare('UPDATE mos_person SET version=version+1 WHERE workspace_id=? AND id=?');
    $s->execute([$row['workspace_id'],$row['id']]);
    try{restoreFails(fn()=>$attestor->attest(),'RESTORE_CONTENT_DIVERGED');}
    finally{$restored->prepare('UPDATE mos_person SET version=? WHERE workspace_id=? AND id=?')->execute([$row['version'],$row['workspace_id'],$row['id']]);}
});
checkFullRestore('altered ciphertext archive reason is detected after imported SQL restore',function()use($attestor,$restored){
    $row=$restored->query('SELECT workspace_id,source_message_id,reason_code FROM mos_messenger_wire_quarantine LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    mustFullRestore(is_array($row));
    $q=$restored->prepare('UPDATE mos_messenger_wire_quarantine SET reason_code=? WHERE workspace_id=? AND source_message_id=?');
    $q->execute(['MANUALLY_CHANGED',$row['workspace_id'],$row['source_message_id']]);
    try{restoreFails(fn()=>$attestor->attest(),'RESTORE_CONTENT_DIVERGED');}
    finally{$q->execute([$row['reason_code'],$row['workspace_id'],$row['source_message_id']]);}
});
checkFullRestore('separate SQL connection into same source database is rejected as fake restore',function()use($source){
    restoreFails(fn()=>(new MosDatabaseRestoreAttestor($source,$source))->attest(),'RESTORE_SAME_DATABASE');
});
checkFullRestore('correctly restored full schema and durable records re-attest after tamper restoration',function()use($attestor,&$baseline){
    $again=$attestor->attest();
    mustFullRestore($again===$baseline);
});
echo "C4-07B3B3B2 SQL BACKUP $passed passed, $failed failed\n";
exit($failed===0?0:1);
