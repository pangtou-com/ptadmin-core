<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Updates\{ApplicationPackageBuilder, PackageManifest, UpdateFiles};
use Symfony\Component\Process\Process;

/** 工作区集成验收：复用已安装依赖，Composer 只在构建阶段生成可搬迁的生产 autoload。 */
final class OfficialApplicationLifecycleTest extends TestCase
{
    public function testProductionInstallationAndFullAndPatchUpdatesWithoutRuntimeComposer(): void
    {
        $admin = getenv('PTADMIN_ADMIN_TEST_ROOT');
        $composerBinary = getenv('PTADMIN_TEST_COMPOSER');
        if (!$admin || !$composerBinary) {
            self::markTestSkipped('设置 PTADMIN_ADMIN_TEST_ROOT 和 PTADMIN_TEST_COMPOSER 运行真实基础壳产物验收。');
        }
        $workspace = sys_get_temp_dir().'/ptadmin-lifecycle-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        UpdateFiles::directory($workspace);
        try {
            $core = dirname(__DIR__, 2);
            $lock = UpdateFiles::read($admin.'/composer.lock');
            $installed = UpdateFiles::read($admin.'/vendor/composer/installed.json');
            $productionNames = array_column($lock['packages'], 'name');
            $installed['packages'] = array_values(array_filter($installed['packages'], static fn ($package) => in_array($package['name'], $productionNames, true)));
            $installed['dev'] = false;
            $installed['dev-package-names'] = [];
            $reports = [];
            foreach (['0.14.0', '0.14.1'] as $version) {
                $root = $workspace.'/build-'.$version;
                foreach (['app', 'bootstrap/app.php', 'bootstrap/update-guard.php', 'config', 'routes', 'resources', 'templates', 'database/migrations', 'public/index.php', 'artisan', '.env.example', 'composer.json'] as $path) {
                    if (file_exists($admin.'/'.$path)) {
                        UpdateFiles::copy($admin.'/'.$path, $root.'/'.$path);
                    }
                }
                foreach ($productionNames as $name) {
                    UpdateFiles::copy($admin.'/vendor/'.$name, $root.'/vendor/'.$name);
                }
                $filesystem->deleteDirectory($root.'/vendor/ptadmin/core');
                foreach (['src', 'config', 'database', 'resources', 'routes', 'composer.json'] as $path) {
                    UpdateFiles::copy($core.'/'.$path, $root.'/vendor/ptadmin/core/'.$path);
                }
                // 夹具使用两个独立版本，不依赖工作区当前 Shell 的发布版本。
                $shellRoot = $root.'/vendor/ptadmin/core/resources/admin-frontend';
                $shell = UpdateFiles::read($shellRoot.'/manifest.json');
                $shell['frontend_version'] = $version;
                $shell['backend_constraint'] = '^0.14';
                UpdateFiles::write($shellRoot.'/manifest.json', $shell);
                if ($version === '0.14.1') {
                    $shellRoot = $root.'/vendor/ptadmin/core/resources/admin-frontend';
                    $shell = UpdateFiles::read($shellRoot.'/manifest.json');
                    $shell['frontend_version'] = '0.14.1';
                    file_put_contents($shellRoot.'/index.html', "\n<!-- package lifecycle target -->", FILE_APPEND);
                    foreach ($shell['files'] as &$file) {
                        if ($file['path'] === 'index.html') {
                            $file['size'] = filesize($shellRoot.'/index.html');
                            $file['sha256'] = hash_file('sha256', $shellRoot.'/index.html');
                        }
                    }
                    unset($file);
                    $shell['artifact_sha256'] = hash('sha256', json_encode($shell['files'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                    UpdateFiles::write($shellRoot.'/manifest.json', $shell);
                }
                $composer = UpdateFiles::read($root.'/composer.json');
                $composer['require']['ptadmin/core'] = $version;
                UpdateFiles::write($root.'/composer.json', $composer);
                foreach (['lock', 'installed'] as $source) {
                    foreach (${$source}['packages'] as &$package) {
                        if ($package['name'] === 'ptadmin/core') {
                            $package['version'] = $version;
                            $package['version_normalized'] = $version.'.0';
                            $package['source']['reference'] = str_repeat('a', 40);
                            $package['dist']['reference'] = str_repeat('a', 40);
                        }
                    }
                    unset($package);
                }
                UpdateFiles::write($root.'/composer.lock', $lock);
                UpdateFiles::write($root.'/vendor/composer/installed.json', $installed);
                $this->process([PHP_BINARY, $composerBinary, 'install', '--no-interaction', '--no-dev', '--no-scripts', '--no-plugins'], $root, ['COMPOSER_DISABLE_NETWORK' => '1']);
                // 外部发行目录通过受控 HTTP fake 注入，安装、迁移、资源同步、独立 Runner 均执行真实代码。
                $provider = $root.'/app/Providers/AppServiceProvider.php';
                $code = file_get_contents($provider);
                $code = str_replace('$this->loadViewsFrom', <<<'PHP'
if ($fixture = getenv('PTADMIN_PACKAGE_TEST_CATALOG')) {
            \Illuminate\Support\Facades\Http::preventStrayRequests();
            \Illuminate\Support\Facades\Http::fake(function ($request) use ($fixture) {
                $data = json_decode(file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
                $url = $request->url();
                if (isset($data['downloads'][$url])) {
                    return \Illuminate\Support\Facades\Http::response(file_get_contents($data['downloads'][$url]));
                }
                return \Illuminate\Support\Facades\Http::response(['code' => 0, 'data' => ['document' => $data['document']]]);
            });
        }
        $this->loadViewsFrom
PHP, $code);
                file_put_contents($provider, $code);
                $reports[$version] = (new ApplicationPackageBuilder())->build($root, $workspace.'/release-'.$version,
                    $version, str_repeat('b', 40), '^0.14', $version === '0.14.1' ? $workspace.'/build-0.14.0' : null);
            }
            foreach (['full', 'patch', 'composer'] as $kind) {
                $root = $workspace.'/runtime-'.$kind;
                $zip = new \ZipArchive();
                self::assertTrue($zip->open($workspace.'/release-0.14.0/install.zip'));
                self::assertTrue($zip->extractTo($root));
                $zip->close();
                if ($kind === 'composer') {
                    // 模拟 create-project 的默认开发依赖与本机生成 autoload，不携带安装包身份。
                    unlink($root.'/'.PackageManifest::IDENTITY);
                    $localInstalled = UpdateFiles::read($root.'/vendor/composer/installed.json');
                    $devNames = array_column($lock['packages-dev'], 'name');
                    $hostInstalled = UpdateFiles::read($admin.'/vendor/composer/installed.json');
                    $devPackages = array_values(array_filter($hostInstalled['packages'], static fn ($package) => in_array($package['name'], $devNames, true)));
                    self::assertCount(count($devNames), $devPackages, '验收宿主必须安装锁定的默认开发依赖');
                    foreach ($devNames as $name) {
                        UpdateFiles::copy($admin.'/vendor/'.$name, $root.'/vendor/'.$name);
                    }
                    $localInstalled['packages'] = [...$localInstalled['packages'], ...$devPackages];
                    $localInstalled['dev'] = true;
                    $localInstalled['dev-package-names'] = $devNames;
                    UpdateFiles::write($root.'/vendor/composer/installed.json', $localInstalled);
                    $this->process([PHP_BINARY, $composerBinary, 'dump-autoload', '--no-interaction', '--no-scripts', '--no-plugins'], $root, ['COMPOSER_DISABLE_NETWORK' => '1']);
                }
                self::assertFileDoesNotExist($root.'/.env');
                file_put_contents($root.'/lifecycle.php', file_get_contents(__DIR__.'/../Fixtures/Updates/application-lifecycle.php'));
                $catalog = $this->catalog($reports, $workspace, $kind);
                UpdateFiles::write($workspace.'/catalog-'.$kind.'.json', $catalog);
                $environment = ['PATH' => '/nonexistent', 'PTADMIN_PACKAGE_TEST_CATALOG' => $workspace.'/catalog-'.$kind.'.json',
                    'PTADMIN_UPDATE_PHP_BINARY' => PHP_BINARY, 'PTADMIN_UPDATE_DELIVERY' => 'manual',
                    'PTADMIN_UPDATE_COORDINATOR' => '1'];
                $this->process([PHP_BINARY, 'lifecycle.php', 'install'], $root, $environment);
                self::assertFileExists($root.'/storage/ptadmin.json');
                file_put_contents($root.'/app/AddedAfterBuild.php', '<?php namespace App; final class AddedAfterBuild { public const VALUE = "business-kept"; }');
                $metadata = UpdateFiles::read($root.'/composer.json');
                $metadata['name'] = 'customer/application';
                $metadata['description'] = '保留项目说明';
                UpdateFiles::write($root.'/composer.json', $metadata);
                foreach (['modules/app/keep.js', 'plugins/example/keep.js', 'user.txt'] as $path) {
                    UpdateFiles::directory(dirname($root.'/public/package_admin/'.$path));
                    file_put_contents($root.'/public/package_admin/'.$path, 'user-content');
                }
                $this->process([PHP_BINARY, 'lifecycle.php', 'check'], $root, $environment);
                if ($kind === 'full') {
                    // 从持有共享入口锁的真实 Artisan 发起，验证 Shell 独立协调进程不会自锁。
                    $this->process([PHP_BINARY, 'artisan', 'ptadmin:updates:install', 'admin-shell', '0.14.1'], $root,
                        array_replace($environment, ['PTADMIN_UPDATE_COORDINATOR' => '0']));
                    $deadline = microtime(true) + 45;
                    $shellTask = null;
                    do {
                        foreach (glob($root.'/storage/app/ptadmin/tasks/*/state.json') as $statePath) {
                            $state = UpdateFiles::read($statePath);
                            if ($state['type'] === 'admin_frontend.update') {
                                $shellTask = $state;
                            }
                        }
                        if (in_array($shellTask['status'] ?? '', ['succeeded', 'failed'], true)) {
                            break;
                        }
                        usleep(100000);
                    } while (microtime(true) < $deadline);
                    self::assertSame('succeeded', $shellTask['status'] ?? null, json_encode($shellTask, JSON_UNESCAPED_UNICODE));
                    self::assertSame('0.14.1', UpdateFiles::read($root.'/public/package_admin/manifest.json')['frontend_version']);
                    self::assertFileDoesNotExist($root.'/storage/framework/down');
                }
                $this->process([PHP_BINARY, 'artisan', 'ptadmin:updates:install', 'core', '0.14.1', '--database-backup-confirmed'], $root, $environment);
                PackageManifest::verify($root, $reports['0.14.1']['target']);
                $this->process([PHP_BINARY, 'lifecycle.php', 'check'], $root, $environment);
                self::assertFileDoesNotExist($root.'/storage/framework/down');
                $plans = array_values(array_filter(glob($root.'/storage/app/ptadmin/updates/operations/*/plan.json'),
                    static fn ($path): bool => isset(UpdateFiles::read($path)['artifact'])));
                self::assertCount(1, $plans);
                self::assertSame($kind === 'composer' ? 'full' : $kind, UpdateFiles::read($plans[0])['artifact']['kind']);
                if ($kind === 'composer') {
                    self::assertNotNull(UpdateFiles::read($plans[0])['composer_source']);
                    self::assertDirectoryDoesNotExist($root.'/vendor/phpunit/phpunit');
                    self::assertDirectoryExists(dirname($plans[0]).'/backup/0/phpunit/phpunit');
                    self::assertSame('standard', (new \PTAdmin\Core\Updates\DependencyBaseline())->inspect($root, true)['status']);
                }
                self::assertSame('customer/application', UpdateFiles::read($root.'/composer.json')['name']);
                $this->process([PHP_BINARY, $composerBinary, 'validate', '--no-check-publish'], $root, ['COMPOSER_DISABLE_NETWORK' => '1']);
                self::assertSame('0.14.1', UpdateFiles::read($root.'/public/package_admin/manifest.json')['frontend_version']);
                foreach (['modules/app/keep.js', 'plugins/example/keep.js', 'user.txt'] as $path) {
                    self::assertSame('user-content', file_get_contents($root.'/public/package_admin/'.$path));
                }
            }
        } finally {
            if (getenv('PTADMIN_KEEP_PACKAGE_FIXTURE') === '1') {
                fwrite(STDERR, '验收夹具保留在 '.$workspace."\n");
            } else {
                $filesystem->deleteDirectory($workspace);
            }
        }
    }

    private function process(array $command, string $root, array $environment): void
    {
        $process = new Process($command, $root, $environment);
        $process->setTimeout(180)->run();
        self::assertTrue($process->isSuccessful(), implode(' ', $command)."\n".$process->getOutput().$process->getErrorOutput());
    }

    private function catalog(array $reports, string $workspace, string $kind): array
    {
        $results = [];
        $downloads = [];
        $id = 0;
        $url = \PTAdmin\Cloud\CloudProtocol::SITE_URL;
        foreach ($reports as $version => $report) {
            $artifacts = [];
            foreach ($report['artifacts'] as $artifact) {
                if ($kind === 'full' && $artifact['kind'] === 'patch') {
                    continue;
                }
                $artifact['id'] = ++$id;
                $artifact['url'] = $url.'/api/cloud/official/artifacts/'.$id;
                $downloads[$artifact['url']] = $workspace.'/release-'.$version.'/'.$artifact['name'];
                $artifacts[] = $artifact;
            }
            $application = ['target' => array_diff_key($report['target'], array_flip(['files', 'baseline'])), 'artifacts' => $artifacts];
            foreach (['core', 'application-template'] as $component) {
                $results[] = ['component' => $component, 'version' => $version, 'status' => 'published', 'git_commit' => str_repeat($component === 'core' ? 'a' : 'b', 40),
                    'composer_package' => $component === 'core' ? 'ptadmin/core' : 'ptadmin/admin', 'composer_version' => $version,
                    'compatibility' => ['php' => '^8.2', 'laravel' => '^12.68', 'core' => '^0.14'], 'github_url' => '', 'gitee_url' => '',
                    'changelog' => '', 'breaking_changes' => '', 'upgrade_steps' => '', 'rollback_steps' => '', 'application' => $application];
            }
        }
        foreach ($reports as $report) {
            $shell = $report['target']['shell'];
            $shellRoot = $workspace.'/build-'.$report['target']['version'].'/vendor/ptadmin/core/resources/admin-frontend';
            $shellArchive = $workspace.'/shell-'.$shell['version'].'.zip';
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($shellArchive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
            $manifest = UpdateFiles::read($shellRoot.'/manifest.json');
            foreach ([...array_column($manifest['files'], 'path'), 'manifest.json'] as $path) {
                $zip->addFile($shellRoot.'/'.$path, $path);
            }
            $zip->close();
            $packages = [];
            foreach (['source', 'build'] as $type) {
                $packageUrl = $url.'/api/cloud/official/releases/admin-shell/'.$shell['version'].'/packages/'.$type;
                $packages[$type] = ['size' => filesize($shellArchive), 'sha256' => hash_file('sha256', $shellArchive), 'url' => $packageUrl];
                $downloads[$packageUrl] = $shellArchive;
            }
            $results[] = ['component' => 'admin-shell', 'version' => $shell['version'], 'status' => 'published',
            'artifact_sha256' => $shell['artifact_sha256'], 'compatibility' => ['core' => $shell['backend_constraint'], 'api_protocol' => $shell['api_protocol'], 'schema_protocol' => $shell['schema_protocol']],
            'github_url' => '', 'gitee_url' => '', 'changelog' => '', 'breaking_changes' => '', 'upgrade_steps' => '', 'rollback_steps' => '',
            'packages' => $packages];
        }
        return ['document' => ['protocol_version' => 2, 'generated_at' => gmdate('c'), 'expires_at' => gmdate('c', time() + 3600), 'results' => $results, 'advisories' => []], 'downloads' => $downloads];
    }
}
