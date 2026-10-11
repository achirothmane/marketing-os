<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/runtime.php';

use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Transport\MosWireQuarantineKeyManager;

/**
 * Case review is AUTHENTICATION + HOLD only. No plaintext, approve, replay,
 * DELETE, UPDATE, decryption export, provider effects, or change of policy.
 */
try {
    if(getenv('MOS_DLQ_REVIEW_ENABLED')!=='1' ||
       getenv('MOS_QUEUE_MODE')!=='SHADOW')throw new RuntimeException('REVIEW_DISABLED');
    if($argc!==5)throw new RuntimeException('REVIEW_ARGS_INVALID');
    $workspace=WorkspaceId::fromString($argv[1]);
    $keyId=$argv[2];
    $countArg=$argv[3];$cursorArg=$argv[4];
    if(!preg_match('/^[A-Za-z0-9_.-]{1,32}$/D',$keyId)||
       !preg_match('/^[1-9][0-9]*$/D',$countArg)||
       !preg_match('/^(0|[1-9][0-9]*)$/D',$cursorArg)||
       (int)$countArg>100||(float)$cursorArg>2147483647) {
       throw new RuntimeException('REVIEW_ARGS_INVALID');
    }
    $keyB64=getenv('MOS_DLQ_REVIEW_KEY_B64');
    $key=is_string($keyB64)?base64_decode($keyB64,true):false;
    if(!is_string($key)||strlen($key)!==32)throw new RuntimeException('REVIEW_KEY_MISSING');
    $db=mos_runtime_connection('READ');
    $manager=new MosWireQuarantineKeyManager($db);
    $db->beginTransaction();
    try {
        $verified=$manager->verify($workspace,$keyId,$key,(int)$countArg,(int)$cursorArg);
        $q=$db->prepare('SELECT source_message_id,key_id,reason_code,payload_sha256
          FROM mos_messenger_wire_quarantine
          WHERE workspace_id=? AND key_id=? AND source_message_id>?
          ORDER BY source_message_id LIMIT '.(int)$countArg);
        $q->execute([(string)$workspace,$keyId,(int)$cursorArg]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==$verified['verified']||
            ($rows!==[]&&(int)end($rows)['source_message_id']!==$verified['next_cursor'])) {
            throw new RuntimeException('REVIEW_SNAPSHOT_CHANGED');
        }
        $inventory=$manager->inventory($workspace);
        $db->commit();
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
    $cases=array_map(static fn(array $r):array=>[
        'case_id'=>(int)$r['source_message_id'],
        'key_id'=>(string)$r['key_id'],
        'reason_code'=>(string)$r['reason_code'],
        'ciphertext_sha256'=>(string)$r['payload_sha256'],
        'disposition'=>'HOLD_FOR_OPERATOR'
    ],$rows);
    $other=$inventory['total']-($inventory['key_counts'][$keyId]??0);
    echo json_encode([
        'mode'=>'DLQ_READ_ONLY_REVIEW',
        'decision'=>'HOLD_FOR_OPERATOR',
        'workspace_id'=>(string)$workspace,
        'cases'=>$cases,
        'verified'=>$verified['verified'],
        'next_cursor'=>$verified['next_cursor'],
        'has_more'=>$verified['has_more'],
        'other_key_archive_count'=>$other,
        'review_authority'=>'NONE',
        'auto_redrive'=>false
    ],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $e) {
    $allowed=['REVIEW_DISABLED','REVIEW_ARGS_INVALID','REVIEW_KEY_MISSING',
        'REVIEW_SNAPSHOT_CHANGED','ARCHIVE_AUTHENTICATION_FAILED',
        'ARCHIVE_SOURCE_MISMATCH'];
    $reason=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'REVIEW_REFUSED';
    fwrite(STDERR,"MOS DLQ review blocked: ".$reason."\n");
    exit(2);
}
