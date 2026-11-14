<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Env;

require dirname(__DIR__, 3).'/vendor/autoload.php';

// 新进程只读取本次测试的环境文件，避免宿主或 PHPUnit 环境变量覆盖测试值。
$path = $argv[1];
$keys = array_keys(Dotenv::parse((string) file_get_contents($path)));
foreach ($keys as $key) {
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
}
Dotenv::create(Env::getRepository(), dirname($path), basename($path))->load();
$values = [];
foreach ($keys as $key) {
    $values[$key] = Env::get($key);
}
$result = ['values' => $values];

if (($argv[2] ?? '') === '--database') {
    $database = Env::get('DB_DATABASE');
    if (!str_starts_with($database, DIRECTORY_SEPARATOR)) {
        $database = dirname($path).'/'.$database;
    }
    $capsule = new Manager();
    $capsule->addConnection([
        'driver' => Env::get('DB_CONNECTION'),
        'database' => $database,
        'prefix' => Env::get('DB_PREFIX', ''),
        'foreign_key_constraints' => true,
    ]);
    $result['administrator'] = $capsule->getConnection()->table('admins')->sole()->username;
}

echo json_encode($result, JSON_THROW_ON_ERROR);
