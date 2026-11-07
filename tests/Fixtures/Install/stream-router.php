<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Http\Request;
use Orchestra\Testbench\Foundation\Application;
use PTAdmin\Core\Install\InstallStateStore;
use PTAdmin\Core\Providers\PTAdminServiceProvider;

require dirname(__DIR__, 3).'/vendor/autoload.php';

if ($_SERVER['REQUEST_URI'] === '/health') {
    echo 'ready';
    return;
}

$root = getenv('PTADMIN_STREAM_TEST_ROOT');
if (!is_string($root) || !is_dir($root)) {
    throw new RuntimeException('An isolated installation test directory is required.');
}

// 模拟 PHP 默认缓冲之外，宿主或中间件增加的嵌套缓冲。
ob_start();
ob_start();

$app = Application::create(null, static function ($app) use ($root): void {
    $app->useStoragePath($root.'/storage');
    $app->usePublicPath($root.'/public');
    $app->useEnvironmentPath($root);
    $app->booting(static function ($app) use ($root): void {
        $app['config']->set([
            'app.key' => '',
            'app.locale' => 'zh_CN',
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'ptadmin.install.folders' => [],
            'ptadmin.install.files' => [],
            'ptadmin.addon.path' => $root.'/addons',
            'cache.default' => 'array',
            'session.driver' => 'array',
            'logging.default' => 'single',
            'logging.channels.single.path' => $root.'/storage/laravel.log',
            'view.compiled' => $root.'/storage/views',
        ]);
    });
}, ['extra' => ['providers' => [PTAdminServiceProvider::class]]]);

$state = $app->make(InstallStateStore::class);
if (!is_dir($state->directory())) {
    mkdir($state->directory(), 0700, true);
}
file_put_contents($state->directory().'/agreement.lock', (string) time());

$app['events']->listen(MigrationsStarted::class, static function () use ($root): void {
    file_put_contents($root.'/migration-waiting', '1');
    $deadline = microtime(true) + 8;
    // 客户端收到迁移日志后才放行；避免用安装总耗时猜测是否实时输出。
    while (!is_file($root.'/continue-migration')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('The client did not receive installation progress before migration.');
        }
        usleep(10000);
    }
    if (is_file($root.'/fail-migration')) {
        throw new RuntimeException('Isolated migration failure.');
    }
});

$kernel = $app->make(Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
