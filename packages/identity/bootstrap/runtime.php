<?php
declare(strict_types=1);

/**
 * Runtime-only bootstrap. Never import tests/bootstrap.php or read MOS_TEST_*.
 * Each operation must explicitly select READ, WRITE or MIGRATE credentials.
 */
spl_autoload_register(static function(string $class): void {
    foreach ([
        'MarketingOS\\Contracts\\' => dirname(__DIR__,2).'/contracts/src/',
        'MarketingOS\\Identity\\' => dirname(__DIR__).'/src/',
    ] as $prefix => $dir) {
        if (!str_starts_with($class,$prefix)) continue;
        $path=$dir.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if (is_file($path)) require $path;
        return;
    }
});

/** @return array{dsn:string,user:string,password:string,host:string,port:int,dbname:string} */
function mos_runtime_credentials(string $role):array {
    if (!in_array($role,['READ','WRITE','MIGRATE'],true)) {
        throw new RuntimeException('RUNTIME_ROLE_INVALID');
    }
    $prefix='MOS_RUNTIME_'.$role.'_';
    $dsn=getenv($prefix.'DSN');
    $user=getenv($prefix.'DB_USER');
    $password=getenv($prefix.'DB_PASSWORD');
    if (!is_string($dsn)||!is_string($user)||!is_string($password)||
        $user===''||$password==='') {
        throw new RuntimeException('RUNTIME_CREDENTIALS_MISSING');
    }
    // A strict, intentionally narrow TCP-only DSN avoids silent socket/fallback
    // behavior, injected PDO attributes or re-targeted databases.
    if (!preg_match('/^mysql:host=([A-Za-z0-9._:-]+);port=([0-9]{1,5});dbname=([A-Za-z0-9_]+);charset=utf8mb4$/D',$dsn,$m)) {
        throw new RuntimeException('RUNTIME_DSN_INVALID');
    }
    $port=(int)$m[2];
    if ($port<1||$port>65535)throw new RuntimeException('RUNTIME_DSN_INVALID');
    return ['dsn'=>$dsn,'user'=>$user,'password'=>$password,
            'host'=>$m[1],'port'=>$port,'dbname'=>$m[3]];
}
function mos_runtime_connection(string $role):\PDO {
    $s=mos_runtime_credentials($role);
    try {
        return new \PDO($s['dsn'],$s['user'],$s['password'],[
            \PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES=>false,
            \PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT=>5,
        ]);
    } catch (\Throwable $e) {
        // Never propagate authentication errors or the DSN in runtime diagnostics.
        throw new RuntimeException('RUNTIME_CONNECTION_REFUSED',0,$e);
    }
}
/** @return array<string,mixed> Doctrine transport params from WRITE role only. */
function mos_runtime_transport_params():array {
    $s=mos_runtime_credentials('WRITE');
    return ['driver'=>'pdo_mysql','host'=>$s['host'],'port'=>$s['port'],
            'dbname'=>$s['dbname'],'user'=>$s['user'],
            'password'=>$s['password'],'charset'=>'utf8mb4'];
}
