<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PTAdmin\Core\Providers\PTAdminServiceProvider;
use PTAdmin\Core\Tasks\{TaskManager, TaskStore};
use PTAdmin\Core\Updates\{InstalledVersions, OfficialUpdateInstaller, OfficialShellUpdater, OfficialShellArchive, UpdateException, UpdateFiles};
use ZipArchive;

final class OfficialUpdateInstallTest extends TestCase
{
    private string $directory;
    private array $catalog;
    private array $versions;
    private string $archive;

    protected function getPackageProviders($app): array { return [PTAdminServiceProvider::class]; }

    protected function defineEnvironment($app): void
    {
        $this->directory = sys_get_temp_dir().'/ptadmin-update-install-'.bin2hex(random_bytes(6));
        $app->useStoragePath($this->directory.'/storage');
        $app->usePublicPath($this->directory.'/public');
        UpdateFiles::write($this->directory.'/storage/app/ptadmin/install/installed.json', ['status' => 'installed', 'core_version' => '1.0.0', 'web_prefix' => 'admin_test']);
        $app['config']->set('ptadmin.tasks.path', $this->directory.'/tasks');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setBasePath($this->directory);
        config(['ptadmin.web_prefix' => 'admin_test', 'ptadmin-updates.path' => $this->directory.'/updates',
            'ptadmin-updates.delivery' => 'manual', 'ptadmin.tasks.path' => $this->directory.'/tasks']);
        $old = $this->bundle(public_path('admin_test'), '1.0.0', ['index.html' => 'old', 'assets/old.js' => 'old']);
        $new = $this->bundle($this->directory.'/bundle', '1.1.0', ['index.html' => 'new', 'assets/new.js' => 'new']);
        $this->archive = $this->directory.'/new.zip';
        $zip = new ZipArchive();
        $zip->open($this->archive, ZipArchive::CREATE);
        foreach (['index.html', 'assets/new.js', 'manifest.json'] as $path) {
            $zip->addFile($this->directory.'/bundle/'.$path, $path);
        }
        $zip->close();
        $this->versions = ['core' => ['version' => '1.0.0', 'source' => 'composer', 'known' => true],
            'admin-shell' => ['version' => '1.0.0', 'source' => 'deployed_manifest', 'known' => true, 'custom' => false, 'integrity' => 'verified', 'artifact_sha256' => $old['artifact_sha256']],
            'php' => PHP_VERSION, 'laravel' => '12.68.0', 'api_protocol' => 1, 'schema_protocol' => 1];
        $reader = new class extends InstalledVersions {
            public array $versions;
            public function read(): array { return $this->versions; }
        };
        $reader->versions = &$this->versions;
        $this->app->instance(InstalledVersions::class, $reader);
        $this->catalog = ['protocol_version' => 1, 'generated_at' => gmdate('c', time() - 10), 'expires_at' => gmdate('c', time() + 3600),
            'results' => [$this->release('1.0.0', $old), $this->release('1.1.0', $new)], 'advisories' => []];
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            return str_ends_with($request->url(), '/releases')
                ? Http::response(['code' => 0, 'data' => ['document' => $this->catalog]])
                : Http::response(file_get_contents($this->archive));
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->directory);
    }

    private function bundle(string $path, string $version, array $contents): array
    {
        ksort($contents);
        $files = [];
        foreach ($contents as $name => $body) {
            UpdateFiles::directory(dirname($path.'/'.$name));
            file_put_contents($path.'/'.$name, $body);
            $files[] = ['path' => $name, 'size' => strlen($body), 'sha256' => hash('sha256', $body)];
        }
        $manifest = ['manifest_version' => 1, 'frontend_version' => $version, 'backend_constraint' => '^1.0',
            'api_protocol' => 1, 'schema_protocol' => 1, 'entry' => './index.html', 'files' => $files,
            'artifact_sha256' => hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES))];
        UpdateFiles::write($path.'/manifest.json', $manifest);
        return $manifest;
    }

    private function release(string $version, array $manifest): array
    {
        $packages = [];
        foreach (['source', 'build'] as $kind) {
            $packages[$kind] = ['url' => 'https://cloud.api.pangtou.com/api/cloud/official/releases/admin-shell/'.$version.'/packages/'.$kind,
                'size' => filesize($this->archive), 'sha256' => hash_file('sha256', $this->archive)];
        }
        return ['component' => 'admin-shell', 'version' => $version, 'status' => 'published', 'compatibility' => ['core' => '^1.0', 'api_protocol' => 1, 'schema_protocol' => 1],
            'github_url' => '', 'gitee_url' => '', 'changelog' => '', 'breaking_changes' => '', 'upgrade_steps' => '', 'rollback_steps' => '',
            'artifact_sha256' => $manifest['artifact_sha256'], 'packages' => $packages];
    }

    private function execute(): array
    {
        $task = app(OfficialUpdateInstaller::class)->submit('admin-shell', '1.1.0', 1);
        $tasks = app(TaskManager::class);
        $tasks->start($task['task_id']);
        $result = app(OfficialShellUpdater::class)->execute($tasks->getForExecution($task['task_id']), $tasks);
        $tasks->succeed($task['task_id'], $result);
        return $tasks->get($task['task_id']);
    }

    public function testShellUpdateRemainsAvailableWithoutComposer(): void
    {
        config(['ptadmin-updates.composer_path' => $this->directory.'/missing-composer']);
        $this->versions['application-template'] = ['version' => null, 'known' => false];
        $this->versions['host_version'] = null;
        $service = app(\PTAdmin\Core\Updates\OfficialUpdateService::class);
        $service->check();
        $overview = $service->overview(1, true, ['admin-shell' => true]);
        self::assertFalse($overview['environment']['online_core_update']);
        self::assertTrue($overview['components'][1]['update']['available']);
        self::assertSame('succeeded', $this->execute()['status']);
    }

    public function testSequenceChecksIntermediateCompatibilityAndRecoveryBeforeOfferingUpdates(): void
    {
        $this->versions['application-template'] = ['version' => null, 'known' => false];
        $this->versions['host_version'] = null;
        $this->catalog['results'][1]['compatibility']['core'] = '^1.1';
        $this->catalog['results'][] = ['component' => 'core', 'version' => '1.1.0', 'status' => 'published',
            'compatibility' => ['php' => '^8.2', 'laravel' => '^12.68'], 'github_url' => '', 'gitee_url' => '',
            'changelog' => '', 'breaking_changes' => '', 'upgrade_steps' => '', 'rollback_steps' => '',
            'composer_package' => 'ptadmin/core', 'composer_version' => '1.1.0', 'git_commit' => str_repeat('a', 40)];
        $service = app(\PTAdmin\Core\Updates\OfficialUpdateService::class);
        $service->check();
        $overview = $service->overview(1, true, ['core' => true, 'admin-shell' => true]);
        self::assertSame(['component' => 'core', 'version' => '1.1.0', 'target_version' => '1.1.0'], $overview['components'][1]['prerequisite']);
        self::assertFalse($overview['components'][1]['update']['available']);
        self::assertTrue($overview['components'][0]['update']['permitted']);
        $this->versions['core']['version'] = '1.1.0';
        $overview = $service->overview(1, true, ['admin-shell' => true]);
        self::assertNull($overview['components'][1]['prerequisite']);
        self::assertTrue($overview['components'][1]['update']['available']);
        self::assertFalse($service->overview(1, true)['components'][1]['update']['available']);
        $this->versions['core']['version'] = '1.0.0';
        $manifestPath = public_path('admin_test/manifest.json');
        $manifest = UpdateFiles::read($manifestPath);
        $manifest['backend_constraint'] = '<1.1';
        UpdateFiles::write($manifestPath, $manifest);
        self::assertNull($service->overview(1, true)['components'][1]['prerequisite']);
        $manifest['backend_constraint'] = '^1.0';
        UpdateFiles::write($manifestPath, $manifest);
        config(['ptadmin.addon.path' => $this->directory.'/addons']);
        UpdateFiles::write($this->directory.'/addons/example/manifest.json', ['code' => 'example', 'compatibility' => ['ptadmin/core' => '<1.1']]);
        self::assertNull($service->overview(1, true)['components'][1]['prerequisite']);
        UpdateFiles::write($this->directory.'/updates/operations/failed/journal.json', ['status' => 'database_started']);
        $overview = $service->overview(1, true, ['core' => true, 'admin-shell' => true]);
        self::assertTrue($overview['recovery_required']);
        self::assertFalse($overview['components'][0]['update']['available']);
        self::assertFalse($overview['components'][1]['update']['available']);
    }

    public function testOfficialDownloadSwitchesShellAndPreservesModulesPluginsAndUserFiles(): void
    {
        foreach (['modules/app/app.js', 'plugins/cms/plugin.js', 'user.txt'] as $path) {
            UpdateFiles::directory(dirname(public_path('admin_test/'.$path)));
            file_put_contents(public_path('admin_test/'.$path), $path);
        }
        $task = $this->execute();
        self::assertSame('succeeded', $task['status']);
        self::assertSame('new', file_get_contents(public_path('admin_test/index.html')));
        self::assertFileDoesNotExist(public_path('admin_test/assets/old.js'));
        foreach (['modules/app/app.js', 'plugins/cms/plugin.js', 'user.txt'] as $path) {
            self::assertSame($path, file_get_contents(public_path('admin_test/'.$path)));
        }
        self::assertSame('old', file_get_contents($this->directory.'/updates/operations/'.$task['task_id'].'/backup/0/index.html'));
        self::assertTrue($task['result']['data']['reload_required']);
    }

    public function testCorruptDownloadDoesNotChangeRunningShell(): void
    {
        $this->catalog['results'][1]['packages']['build']['sha256'] = str_repeat('f', 64);
        try {
            $this->execute();
            self::fail('摘要错误必须失败');
        } catch (UpdateException $error) {
            self::assertSame('invalid_archive', $error->reason);
        }
        self::assertSame('old', file_get_contents(public_path('admin_test/index.html')));
    }

    public function testExistingUserFileCollisionDoesNotChangeRunningShell(): void
    {
        file_put_contents(public_path('admin_test/assets/new.js'), 'user-file');
        try {
            $this->execute();
            self::fail('不能覆盖用户文件');
        } catch (UpdateException $error) {
            self::assertSame('user_file_conflict', $error->reason);
        }
        self::assertSame('old', file_get_contents(public_path('admin_test/index.html')));
        self::assertSame('user-file', file_get_contents(public_path('admin_test/assets/new.js')));
    }

    public function testWithdrawnTargetIsRecheckedWhenQueuedTaskExecutes(): void
    {
        $task = app(OfficialUpdateInstaller::class)->submit('admin-shell', '1.1.0', 1);
        array_pop($this->catalog['results']);
        $tasks = app(TaskManager::class);
        $tasks->start($task['task_id']);
        $this->expectExceptionMessage('已撤回');
        app(OfficialShellUpdater::class)->execute($tasks->getForExecution($task['task_id']), $tasks);
    }

    public function testGitIsRejectedAndCoreRequiresDatabaseBackup(): void
    {
        config(['ptadmin-updates.delivery' => 'git']);
        try {
            app(OfficialUpdateInstaller::class)->submit('admin-shell', '1.1.0', 1);
            self::fail('Git 环境不能直接更新');
        } catch (UpdateException $error) {
            self::assertStringContainsString('Git/CI', $error->getMessage());
        }
        Http::assertSentCount(1);
        $this->expectExceptionMessage('备份数据库');
        app(OfficialUpdateInstaller::class)->submit('core', '1.1.0', 1);
    }

    public function testCustomShellAndRepeatedSubmissionsCannotReplaceFiles(): void
    {
        $this->versions['admin-shell']['custom'] = true;
        try {
            app(OfficialUpdateInstaller::class)->submit('admin-shell', '1.1.0', 1);
            self::fail('自定义 Shell 不允许覆盖');
        } catch (UpdateException $error) {
            self::assertSame('update_unavailable', $error->reason);
        }
        $this->versions['admin-shell']['custom'] = false;
        $first = app(OfficialUpdateInstaller::class)->submit('admin-shell', '1.1.0', 1);
        try {
            app(OfficialUpdateInstaller::class)->submit('admin-shell', '1.1.0', 1);
            self::fail('不能重复创建更新任务');
        } catch (\PTAdmin\Core\Tasks\TaskException $error) {
            self::assertSame('task_in_progress', $error->errorCode);
            self::assertSame($first['task_id'], $error->details['task_id']);
        }
        self::assertSame(1, app(TaskManager::class)->list()['total']);
        self::assertSame('old', file_get_contents(public_path('admin_test/index.html')));
    }

    public function testArchiveRejectsTraversalSymlinksScriptsAndReservedDirectoriesBeforeExtraction(): void
    {
        foreach (['../outside', 'plugins/cms.js', 'shell.php', '.htaccess', 'link'] as $path) {
            $archive = $this->directory.'/bad-'.md5($path).'.zip';
            $zip = new ZipArchive();
            $zip->open($archive, ZipArchive::CREATE);
            $zip->addFromString($path, 'unsafe');
            if ($path === 'link') {
                $zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
            }
            $zip->close();
            try {
                app(OfficialShellArchive::class)->extract($archive, $this->directory.'/unpacked');
                self::fail('不允许的 ZIP 路径：'.$path);
            } catch (UpdateException $error) {
                self::assertSame('unsafe_archive', $error->reason);
                self::assertDirectoryDoesNotExist($this->directory.'/unpacked');
            }
        }
    }
}
