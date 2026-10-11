<?php
declare(strict_types=1);
namespace MarketingOS\Identity\Runtime;

use RuntimeException;

/**
 * Narrow adapter for systemd LoadCredential-style protected files.
 * No secret is read from argv, source code, an environment file or stdout.
 * The caller passes the four values only to its isolated child processes.
 */
final class MosCredentialDirectory {
    private const FILES = [
        'MOS_RUNTIME_READ_DB_PASSWORD'=>'mos_read_db_password',
        'MOS_RUNTIME_WRITE_DB_PASSWORD'=>'mos_write_db_password',
        'MOS_SNAPSHOT_HMAC_KEY'=>'mos_snapshot_hmac_key',
        'MOS_DLQ_KEY_B64'=>'mos_dlq_key_b64',
    ];

    /** @return array<string,string> */
    public static function load():array {
        $folder=getenv('CREDENTIALS_DIRECTORY');
        if(!is_string($folder)||$folder===''||!str_starts_with($folder,'/')) {
            throw new RuntimeException('CREDENTIAL_DIRECTORY_MISSING');
        }
        $real=realpath($folder);
        if($real===false||!is_dir($real)||is_link($folder)||
            ((int)fileperms($real)&0077)!==0) {
            throw new RuntimeException('CREDENTIAL_DIRECTORY_UNSAFE');
        }
        $result=[];
        foreach(self::FILES as $variable=>$basename) {
            // Explicitly reject inherited secret values to prevent accidental
            // fallback to globally supplied environment secrets.
            if(getenv($variable)!==false) {
                throw new RuntimeException('CREDENTIAL_ENV_COLLISION');
            }
            $path=$real.'/'.$basename;
            $stat=@stat($path);
            if(is_link($path)||!is_file($path)||$stat===false||
               ($stat['mode']&0077)!==0||$stat['size']<1||$stat['size']>4096) {
                throw new RuntimeException('CREDENTIAL_FILE_UNSAFE');
            }
            $value=@file_get_contents($path);
            if(!is_string($value)||str_contains($value,"\0")) {
                throw new RuntimeException('CREDENTIAL_FILE_INVALID');
            }
            // systemd LoadCredential files commonly end in one newline.
            $value=rtrim($value,"\r\n");
            if($value===''||strlen($value)>1024||str_contains($value,"\n")||
                str_contains($value,"\r")) {
                throw new RuntimeException('CREDENTIAL_FILE_INVALID');
            }
            $result[$variable]=$value;
        }
        if(strlen($result['MOS_SNAPSHOT_HMAC_KEY'])<32||
           strlen($result['MOS_RUNTIME_READ_DB_PASSWORD'])<8||
           strlen($result['MOS_RUNTIME_WRITE_DB_PASSWORD'])<8||
           strlen((string)base64_decode($result['MOS_DLQ_KEY_B64'],true))!==32) {
            throw new RuntimeException('CREDENTIAL_FILE_INVALID');
        }
        return $result;
    }
}
