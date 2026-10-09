<?php
declare(strict_types=1);
spl_autoload_register(static function(string $class): void {
    foreach([
        'MarketingOS\\Contracts\\' => dirname(__DIR__,2).'/contracts/src/',
        'MarketingOS\\Identity\\' => dirname(__DIR__).'/src/',
    ] as $prefix=>$dir) {
        if(!str_starts_with($class,$prefix))continue;
        $p=$dir.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if(is_file($p))require $p;
        return;
    }
});
function mos_test_connection(): \PDO {
    $dsn=getenv('MOS_TEST_DSN');
    $user=getenv('MOS_TEST_DB_USER');
    $password=getenv('MOS_TEST_DB_PASSWORD');
    if(!is_string($dsn)||!str_starts_with($dsn,'mysql:')||
       !is_string($user)||!is_string($password)) {
        throw new RuntimeException('Explicit MOS_TEST_DSN mysql: and credentials are required; no default or production fallback.');
    }
    return new \PDO($dsn,$user,$password,[
        \PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_EMULATE_PREPARES=>false,
        \PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,
    ]);
}
