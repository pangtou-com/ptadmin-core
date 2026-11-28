<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'session.driver' => 'array']);
if ($argv[1] === 'install') {
    if ($app['db']->getConnections() !== []) {
        throw new RuntimeException('安装前不应连接业务数据库。');
    }
    app(\PTAdmin\Core\Install\InstallService::class)->install([
        'username' => 'package-founder', 'password' => 'package-test-password', 'password_confirmation' => 'package-test-password',
        'db_connection' => 'sqlite', 'db_database' => 'packages.sqlite', 'db_prefix' => '', 'ptadmin_web_prefix' => 'package_admin',
    ]);
    exit;
}
if (\App\AddedAfterBuild::VALUE !== 'business-kept') {
    throw new RuntimeException('新增 App 类未自动加载。');
}
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$request = \Illuminate\Http\Request::create('/ptadmin/login', 'POST', ['username' => 'package-founder', 'password' => 'package-test-password']);
$request->headers->set('Accept', 'application/json');
$response = $kernel->handle($request);
$body = json_decode($response->getContent(), true);
if ($response->getStatusCode() !== 200 || empty($body['data']['access_token'])) {
    throw new RuntimeException('安装或升级后登录失败：'.$response->getContent());
}
$kernel->terminate($request, $response);
if (\Composer\InstalledVersions::getPrettyVersion('ptadmin/core') === '0.14.0') {
    $updates = app(\PTAdmin\Core\Updates\OfficialUpdateService::class);
    if (!is_file(__DIR__.'/'.\PTAdmin\Core\Updates\PackageManifest::IDENTITY)) {
        if (!$updates->checkOnLogin('baseline-login', time() + 3600)) {
            throw new RuntimeException('首次登录自动检查应成功获取目录');
        }
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        if (is_file(__DIR__.'/'.\PTAdmin\Core\Updates\DependencyBaseline::COMPOSER_IDENTITY)) {
            throw new RuntimeException('登录自动检查不应下载完整包或建立 Composer 基线');
        }
        $store = app(\PTAdmin\Core\Updates\UpdateStateStore::class);
        $store->locked(fn () => $store->write(array_replace($store->read(), ['last_attempt_at' => 0])));
    }
    $updates->check();
    $overview = $updates->overview(1, true, ['core' => true]);
    $core = array_column($overview['components'], null, 'component')['core'];
    if (!$core['update']['available']) {
        throw new RuntimeException('标准安装必须允许后台更新：'.json_encode([$core['update'], $overview['check']], JSON_UNESCAPED_UNICODE));
    }

    $composerPath = __DIR__.'/composer.json';
    $original = file_get_contents($composerPath);
    try {
        $custom = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
        $custom['require']['customer/extra-package'] = '^1.0';
        file_put_contents($composerPath, json_encode($custom, JSON_THROW_ON_ERROR));
        $request = \Illuminate\Http\Request::create('/ptadmin/system/updates/install', 'POST', [
            'component' => 'core', 'version' => '0.14.1', 'database_backup_confirmed' => true,
        ]);
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Authorization', 'Bearer '.$body['data']['access_token']);
        $response = $kernel->handle($request);
        $rejected = json_decode($response->getContent(), true);
        if ($response->getStatusCode() !== 422 || !str_contains($rejected['message'] ?? '', 'customer/extra-package')) {
            throw new RuntimeException('自定义依赖必须由实际安装 API 拒绝：'.$response->getContent());
        }
        $kernel->terminate($request, $response);
    } finally {
        file_put_contents($composerPath, $original);
    }
}
if (\PTAdmin\Schema\Meta\Models\Resource::query()->count() === 0) {
    throw new RuntimeException('Schema 安装结果为空。');
}
app(\PTAdmin\Core\Install\AdminFrontendManifest::class)->verify(public_path('package_admin'));
echo "登录、Schema、Shell 和新增业务类检查通过。\n";
