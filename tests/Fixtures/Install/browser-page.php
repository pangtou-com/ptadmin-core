<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Orchestra\Testbench\Foundation\Application;
use PTAdmin\Core\Install\InstallStateStore;
use PTAdmin\Core\Providers\PTAdminServiceProvider;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$root = sys_get_temp_dir().'/ptadmin-install-browser-'.bin2hex(random_bytes(6));
try {
    $app = Application::create(null, static function ($app) use ($root): void {
        $app->useStoragePath($root);
        $app->booting(static function ($app) use ($root): void {
            $app['config']->set([
                'app.locale' => 'zh_CN',
                'database.default' => 'not_configured',
                'ptadmin.install.extensions' => [],
                'ptadmin.install.folders' => [],
                'ptadmin.install.files' => [],
                'view.compiled' => $root.'/views',
            ]);
        });
    }, ['extra' => ['providers' => [PTAdminServiceProvider::class]]]);
    $state = $app->make(InstallStateStore::class);
    if (!is_dir($state->directory())) {
        mkdir($state->directory(), 0700, true);
    }
    file_put_contents($state->directory().'/agreement.lock', (string) time());
    $response = $app->make(Kernel::class)->handle(Request::create('http://install.example.test/__ptadmin__/install/account'));
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('Unable to render the installation page for browser verification.');
    }
    echo $response->getContent();
} finally {
    (new Filesystem())->deleteDirectory($root);
}
