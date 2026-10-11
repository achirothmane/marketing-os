<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/runtime.php';

use MarketingOS\Contracts\Id\WorkspaceId;
use MarketingOS\Identity\Runtime\MosDLQReviewJournal;

/**
 * Store a previously signed case recommendation. Does not sign, approve,
 * authorize redrive, remove quarantine or contact anybody.
 */
try {
    if(getenv('MOS_REVIEW_RECORD_ENABLED')!=='1' ||
       getenv('MOS_QUEUE_MODE')!=='SHADOW')throw new RuntimeException('REVIEW_DISABLED');
    if($argc!==3)throw new RuntimeException('REVIEW_ARGS_INVALID');
    $workspace=WorkspaceId::fromString($argv[1]);
    $file=$argv[2];
    if(!str_starts_with($file,'/')||is_link($file)||!is_file($file)||
       (fileperms($file)&0077)!==0||filesize($file)>4096||filesize($file)<1) {
        throw new RuntimeException('REVIEW_SIGNED_FILE_UNSAFE');
    }
    $input=json_decode((string)file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
    if(!is_array($input)||array_keys($input)!==['claim','signatures']||
       !is_array($input['claim'])||!is_array($input['signatures'])) {
        throw new RuntimeException('REVIEW_ARGS_INVALID');
    }
    $trustPath=getenv('MOS_REVIEW_TRUST_FILE');
    if(!is_string($trustPath)||!str_starts_with($trustPath,'/')||
       is_link($trustPath)||!is_file($trustPath)||
       (fileperms($trustPath)&0077)!==0||filesize($trustPath)>4096) {
        throw new RuntimeException('REVIEW_TRUST_UNSAFE');
    }
    $trust=json_decode((string)file_get_contents($trustPath),true,16,JSON_THROW_ON_ERROR);
    if(!is_array($trust)||!isset($trust['reviewers'],$trust['approvers'])||
       !is_array($trust['reviewers'])||!is_array($trust['approvers'])) {
        throw new RuntimeException('REVIEW_TRUST_UNSAFE');
    }
    $keyEncoded=getenv('MOS_DLQ_REVIEW_KEY_B64');
    $key=is_string($keyEncoded)?base64_decode($keyEncoded,true):false;
    if(!is_string($key)||strlen($key)!==32)throw new RuntimeException('REVIEW_KEY_MISSING');
    $root=getenv('MOS_REVIEW_JOURNAL_DIR');
    $anchor=getenv('MOS_REVIEW_EXPECTED_HEAD');
    if(!is_string($root)||!is_string($anchor))throw new RuntimeException('REVIEW_STORAGE_UNSAFE');
    $result=(new MosDLQReviewJournal(mos_runtime_connection('READ'),$root))
        ->append($workspace,$input['claim'],$input['signatures'],$trust,$key,$anchor);
    echo json_encode([
        'mode'=>'DLQ_DUAL_SIGNED_REVIEW','result'=>$result,
        'permission'=>'NONE','redrive'=>false,'external_effect'=>false
    ],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $e) {
    $safe=['REVIEW_DISABLED','REVIEW_ARGS_INVALID','REVIEW_SIGNED_FILE_UNSAFE',
      'REVIEW_TRUST_UNSAFE','REVIEW_KEY_MISSING','REVIEW_STORAGE_UNSAFE',
      'REVIEW_ANCHOR_MISMATCH','REVIEW_ANCHOR_INVALID','REVIEW_DUPLICATE',
      'REVIEW_CLAIM_INVALID','REVIEW_AUTHORITY_REFUSED','REVIEW_SIGNATURE_INVALID',
      'REVIEW_EVIDENCE_MISMATCH','ARCHIVE_AUTHENTICATION_FAILED',
      'REVIEW_JOURNAL_CORRUPT','REVIEW_JOURNAL_LIMIT'];
    $reason=in_array($e->getMessage(),$safe,true)?$e->getMessage():'REVIEW_REFUSED';
    fwrite(STDERR,"MOS dual-signed review blocked: ".$reason."\n");
    exit(2);
}
