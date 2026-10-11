<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/runtime.php';

use MarketingOS\Identity\Runtime\MosDLQReviewJournal;

/**
 * Offline one-role signing helper. Never calls a database, reads a plaintext
 * wire, accepts two identities, or records an approval/action.
 * Each operator must run this separately with their own protected key file.
 */
try {
    if(getenv('MOS_REVIEW_SIGN_ENABLED')!=='1')throw new RuntimeException('SIGN_DISABLED');
    if($argc!==3||!in_array($argv[2],['REVIEWER','APPROVER'],true)) {
        throw new RuntimeException('SIGN_INVALID_ARGS');
    }
    $role=$argv[2];$claimPath=$argv[1];
    if(!str_starts_with($claimPath,'/')||is_link($claimPath)||
       !is_file($claimPath)||(fileperms($claimPath)&0077)!==0||
       filesize($claimPath)<1||filesize($claimPath)>4096) {
        throw new RuntimeException('SIGN_INPUT_UNSAFE');
    }
    $claim=json_decode((string)file_get_contents($claimPath),true,16,JSON_THROW_ON_ERROR);
    if(!is_array($claim))throw new RuntimeException('SIGN_INVALID_ARGS');
    $signerId=getenv('MOS_REVIEW_SIGNER_ID');
    if(!is_string($signerId)||
       $signerId!==$claim[$role==='REVIEWER'?'reviewer_id':'approver_id']) {
        throw new RuntimeException('SIGN_IDENTITY_MISMATCH');
    }
    $keyPath=getenv('MOS_REVIEW_SIGNING_KEY_FILE');
    if(!is_string($keyPath)||!str_starts_with($keyPath,'/')||
       is_link($keyPath)||!is_file($keyPath)||
       (fileperms($keyPath)&0077)!==0||
       filesize($keyPath)<1||filesize($keyPath)>4096) {
        throw new RuntimeException('SIGN_KEY_FILE_UNSAFE');
    }
    $secretEncoded=trim((string)file_get_contents($keyPath));
    $secret=base64_decode($secretEncoded,true);
    if(!is_string($secret)||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('SIGN_KEY_INVALID');
    }
    $message=MosDLQReviewJournal::message($claim);
    $signature=base64_encode(sodium_crypto_sign_detached($message,$secret));
    echo json_encode(['role'=>$role,'signer_id'=>$signerId,'signature'=>$signature],
        JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $e) {
    $safe=['SIGN_DISABLED','SIGN_INVALID_ARGS','SIGN_INPUT_UNSAFE',
        'SIGN_IDENTITY_MISMATCH','SIGN_KEY_FILE_UNSAFE','SIGN_KEY_INVALID',
        'REVIEW_CLAIM_INVALID'];
    $reason=in_array($e->getMessage(),$safe,true)?$e->getMessage():'SIGN_REFUSED';
    fwrite(STDERR,"MOS offline review signature refused: ".$reason."\n");
    exit(2);
}
