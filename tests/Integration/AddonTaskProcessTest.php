<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PTAdmin\Addon\Lifecycle\Installer;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Core\Console\Commands\TaskRunCommand;
use PTAdmin\Core\Install\InstallStateStore;
use PTAdmin\Core\Providers\PTAdminServiceProvider;
use PTAdmin\Core\Tasks\AddonTaskProcess;
use PTAdmin\Core\Tasks\TaskManager;

final class AddonTaskProcessTest extends TestCase
{
    private string $root;
    private string $namespace;

    protected function getPackageProviders($app): array
    {
        return [PTAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-addon-process-'.bin2hex(random_bytes(6));
        $this->namespace = 'ProcessAddon'.bin2hex(random_bytes(6));
        foreach (['storage/framework/views', 'bootstrap/cache', 'config', 'public', 'vendor'] as $directory) {
            mkdir($this->root.'/'.$directory, 0700, true);
        }
        touch($this->root.'/database.sqlite');
        $app->useStoragePath($this->root.'/storage');
        $app->usePublicPath($this->root.'/public');
        $app['config']->set([
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => $this->root.'/database.sqlite', 'prefix' => ''],
            'ptadmin.addon.path' => $this->root.'/addons',
            'ptadmin.addon.require_signature' => false,
            'ptadmin-updates.php_binary' => PHP_BINARY,
            'ptadmin.tasks.path' => $this->root.'/storage/app/ptadmin/tasks',
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        \PTAdmin\Admin\Models\Admin::query()->forceCreate(['username' => 'founder', 'password' => 'unused', 'is_founder' => true, 'status' => 1]);
        app(InstallStateStore::class)->markInstalled('0.1.0', 'admintest');
        Artisan::registerCommand(new TaskRunCommand());
        $autoload = var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true);
        file_put_contents($this->root.'/vendor/autoload.php', '<?php require '.$autoload.';');
        file_put_contents($this->root.'/composer.json', '{"name":"test/isolated-host"}');
        file_put_contents($this->root.'/bootstrap/providers.php', '<?php return [\\PTAdmin\\Core\\Providers\\PTAdminServiceProvider::class];');
        file_put_contents($this->root.'/bootstrap/app.php', <<<'BOOT'
<?php
return \Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))
    ->withProviders()->withExceptions()->create();
BOOT);
        file_put_contents($this->root.'/config/app.php', '<?php return '.var_export(['key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'cipher' => 'AES-256-CBC'], true).';');
        file_put_contents($this->root.'/config/auth.php', '<?php return '.var_export([
            'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']],
            'providers' => ['users' => ['driver' => 'eloquent', 'model' => \PTAdmin\User\Models\User::class]],
        ], true).';');
        file_put_contents($this->root.'/config/database.php', '<?php return '.var_export([
            'default' => 'testing',
            'connections' => ['testing' => ['driver' => 'sqlite', 'database' => $this->root.'/database.sqlite', 'prefix' => '']],
        ], true).';');
        file_put_contents($this->root.'/config/ptadmin.php', '<?php return '.var_export([
            'addon' => ['path' => $this->root.'/addons', 'require_signature' => false],
        ], true).';');
        file_put_contents($this->root.'/config/logging.php', '<?php return '.var_export([
            'default' => 'single', 'channels' => ['single' => ['driver' => 'single', 'path' => $this->root.'/storage/test.log']],
        ], true).';');
        // 父进程保持 Testbench 已注册的服务，子进程使用独立的真实 Laravel 入口。
        $this->app->setBasePath($this->root);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->root);
    }

    public function testUpdateUsesNewProviderEvenWhenParentLoadedTheOldClass(): void
    {
        app(Installer::class)->install($this->package('1.0.0'), 'admintest');
        self::assertSame('1.0.0', app('process-addon.version'));
        $task = app(TaskManager::class)->create(TaskManager::ADDON_UPDATE, [
            'source' => $this->package('2.0.0'), 'web_prefix' => 'admintest',
        ]);

        self::assertSame(0, Artisan::call('ptadmin:task:run', ['task_id' => $task['task_id']]), $this->diagnostic($task['task_id']));
        $finished = app(TaskManager::class)->get($task['task_id']);
        self::assertSame('succeeded', $finished['status']);
        self::assertSame(1, $finished['attempt']);
        self::assertSame('2.0.0', app(LifecycleStore::class)->read('process-addon')['version']);
        self::assertSame('2.0.0', file_get_contents($this->root.'/storage/provider-version'));
        self::assertSame('1.0.0', app('process-addon.version'));
    }

    public function testChildFailurePreservesOriginalErrorAndOldPackage(): void
    {
        app(Installer::class)->install($this->package('1.0.0'), 'admintest');
        $source = $this->package('2.0.0', true);
        $task = app(TaskManager::class)->create(TaskManager::ADDON_UPDATE, ['code' => 'process-addon', 'source' => $source]);
        self::assertSame(1, Artisan::call('ptadmin:task:run', ['task_id' => $task['task_id']]));
        $finished = app(TaskManager::class)->get($task['task_id']);
        self::assertSame('provider_test_failed', $finished['error_code'], $this->diagnostic($task['task_id']));
        self::assertSame('1.0.0', app(LifecycleStore::class)->read('process-addon')['version']);
        self::assertStringContainsString('1.0.0', file_get_contents($this->root.'/addons/process-addon/manifest.json'));
    }

    public function testUpdatingDisabledAddonKeepsResourcesAndFrontendDisabled(): void
    {
        app(Installer::class)->install($this->package('1.0.0'), 'admintest');
        app(\PTAdmin\Addon\Lifecycle\StateManager::class)->disable('process-addon');
        $task = app(TaskManager::class)->create(TaskManager::ADDON_UPDATE, [
            'code' => 'process-addon', 'source' => $this->package('2.0.0'), 'web_prefix' => 'admintest',
        ]);
        self::assertSame(0, Artisan::call('ptadmin:task:run', ['task_id' => $task['task_id']]), $this->diagnostic($task['task_id']));
        $state = app(LifecycleStore::class)->read('process-addon');
        self::assertSame('inactive', $state['state']);
        self::assertTrue($state['disabled_by_user']);
        self::assertSame('2.0.0', $state['version']);
        self::assertSame('disabled', \PTAdmin\Schema\Meta\Models\Resource::query()->where('resource_key', 'process.entries')->value('status'));
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('admin_menus')->where('owner_code', 'process-addon')->where('status', 1)->count());
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('admin_resources')->where('owner_code', 'process-addon')->where('status', 1)->count());
        self::assertDirectoryDoesNotExist($this->root.'/public/admintest/plugins/process-addon');
    }

    public function testMissingRuntimeBindingRejectsFreshInstallationAndDeactivatesResources(): void
    {
        $source = $this->package('1.0.0');
        $this->addUnboundAction($source);
        $task = app(TaskManager::class)->create(TaskManager::ADDON_INSTALL, ['code' => 'process-addon', 'source' => $source]);
        self::assertSame(1, Artisan::call('ptadmin:task:run', ['task_id' => $task['task_id']]));
        self::assertSame('runtime_validation_failed', app(TaskManager::class)->get($task['task_id'])['error_code'], $this->diagnostic($task['task_id']));
        self::assertNull(app(LifecycleStore::class)->read('process-addon'));
        self::assertDirectoryDoesNotExist($this->root.'/addons/process-addon');
        self::assertSame('disabled', \PTAdmin\Schema\Meta\Models\Resource::query()->where('resource_key', 'process.entries')->value('status'));
    }

    public function testMissingRuntimeBindingRejectsUpdateAndRestoresActiveSchema(): void
    {
        app(Installer::class)->install($this->package('1.0.0'), 'admintest');
        $source = $this->package('2.0.0');
        $this->addUnboundAction($source);
        $task = app(TaskManager::class)->create(TaskManager::ADDON_UPDATE, ['code' => 'process-addon', 'source' => $source]);
        self::assertSame(1, Artisan::call('ptadmin:task:run', ['task_id' => $task['task_id']]));
        self::assertSame('runtime_validation_failed', app(TaskManager::class)->get($task['task_id'])['error_code'], $this->diagnostic($task['task_id']));
        self::assertSame('1.0.0', app(LifecycleStore::class)->read('process-addon')['version']);
        $resource = \PTAdmin\Schema\Meta\Models\Resource::query()->where('resource_key', 'process.entries')->firstOrFail();
        self::assertSame('active', $resource->status);
        self::assertArrayNotHasKey('publish', $resource->versions()->where('status', 'active')->firstOrFail()->schema_json['actions'] ?? []);
    }

    public function testDirectUpdateRejectsAlreadyLoadedClassesBeforeReplacingFiles(): void
    {
        app(Installer::class)->install($this->package('1.0.0'), 'admintest');
        try {
            app(Installer::class)->install($this->package('2.0.0'), 'admintest', true);
            self::fail('同一 PHP 进程不能替换已加载的插件代码。');
        } catch (\PTAdmin\Addon\Exception\AddonException $exception) {
            self::assertSame('addon_code_already_loaded', $exception->errorCode);
        }
        self::assertSame('1.0.0', app(LifecycleStore::class)->read('process-addon')['version']);
        self::assertStringContainsString('1.0.0', file_get_contents($this->root.'/addons/process-addon/manifest.json'));
    }

    private function addUnboundAction(string $source): void
    {
        $path = $source.'/database/schemas/process.entries.schema.json';
        $schema = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $schema['schema']['actions']['publish'] = ['enabled' => true];
        file_put_contents($path, json_encode($schema, JSON_THROW_ON_ERROR));
    }

    public function testUnavailablePhpFailsWithoutChangingInstalledPackage(): void
    {
        app(Installer::class)->install($this->package('1.0.0'), 'admintest');
        config(['ptadmin-updates.php_binary' => $this->root.'/missing-php']);
        $task = app(TaskManager::class)->create(TaskManager::ADDON_UPDATE, ['code' => 'process-addon', 'source' => $this->package('2.0.0')]);
        self::assertSame(1, Artisan::call('ptadmin:task:run', ['task_id' => $task['task_id']]));
        self::assertSame('failed', app(TaskManager::class)->get($task['task_id'])['status']);
        self::assertSame('1.0.0', app(LifecycleStore::class)->read('process-addon')['version']);
    }

    public function testFailedDisabledUpdateDoesNotRepublishFrontend(): void
    {
        app(Installer::class)->install($this->package('1.0.0'), 'admintest');
        app(\PTAdmin\Addon\Lifecycle\StateManager::class)->disable('process-addon');
        $task = app(TaskManager::class)->create(TaskManager::ADDON_UPDATE, [
            'code' => 'process-addon', 'source' => $this->package('2.0.0', true), 'web_prefix' => 'admintest',
        ]);
        self::assertSame(1, Artisan::call('ptadmin:task:run', ['task_id' => $task['task_id']]));
        self::assertSame('provider_test_failed', app(TaskManager::class)->get($task['task_id'])['error_code']);
        $state = app(LifecycleStore::class)->read('process-addon');
        self::assertSame('inactive', $state['state']);
        self::assertSame('1.0.0', $state['version']);
        self::assertDirectoryDoesNotExist($this->root.'/public/admintest/plugins/process-addon');
    }

    public function testZipPackageWithoutCodeResolvesTargetBeforeBoot(): void
    {
        $source = $this->package('1.0.0');
        $path = $this->root.'/package.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE));
        $zip->addFile($source.'/manifest.json', 'manifest.json');
        $zip->close();
        self::assertSame('process-addon', AddonTaskProcess::target([
            'type' => TaskManager::ADDON_UPDATE, 'payload' => ['source' => $path],
        ]));
    }

    public function testLocalPackageWithoutCodeRetainsTheManifestValidationStage(): void
    {
        $source = $this->package('1.0.0');
        file_put_contents($source.'/manifest.json', '{}');
        $task = app(TaskManager::class)->create(TaskManager::ADDON_INSTALL, ['source' => $source]);
        self::assertSame(1, Artisan::call('ptadmin:task:run', ['task_id' => $task['task_id']]));
        self::assertSame('verifying', app(TaskManager::class)->get($task['task_id'])['stage']);
        self::assertDirectoryDoesNotExist($this->root.'/addons/process-addon');
    }

    public function testCloudInstallAndUpdateUseIsolationButDownloadsAndTemplatesDoNot(): void
    {
        foreach (['install', 'update'] as $operation) {
            self::assertSame('process-addon', AddonTaskProcess::target(['type' => TaskManager::CLOUD_PACKAGE,
                'payload' => ['product_type' => 'addon', 'operation' => $operation, 'code' => 'process-addon']]));
        }
        self::assertNull(AddonTaskProcess::target(['type' => TaskManager::CLOUD_PACKAGE,
            'payload' => ['product_type' => 'addon', 'operation' => 'download']]));
        self::assertNull(AddonTaskProcess::target(['type' => TaskManager::CLOUD_PACKAGE,
            'payload' => ['product_type' => 'template', 'operation' => 'install']]));
    }

    private function package(string $version, bool $fail = false): string
    {
        $directory = $this->root.'/package-'.$version;
        foreach (['src/Providers', 'frontend/dist', 'resources', 'database/schemas'] as $path) {
            mkdir($directory.'/'.$path, 0700, true);
        }
        file_put_contents($directory.'/frontend/dist/entry.js', 'export default {}');
        file_put_contents($directory.'/resources/menu.json', json_encode([[
            'resource_key' => 'process.entries', 'title' => '测试内容', 'type' => 'page', 'path' => 'entries',
            'is_menu' => true, 'entry_permission' => 'process.entries.list',
            'permissions' => [['resource_key' => 'process.entries', 'operations' => [['key' => 'list']]]],
        ]], JSON_THROW_ON_ERROR));
        file_put_contents($directory.'/database/schemas/process.entries.schema.json', json_encode([
            'resource' => 'process.entries', 'table_name' => 'process_entries',
            'schema' => ['name' => 'entries', 'title' => '进程测试', 'fields' => [
                ['name' => 'title', 'type' => 'string', 'label' => '标题', 'description' => '进程隔离测试数据'],
            ]],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($directory.'/manifest.json', json_encode([
            'code' => 'process-addon', 'version' => $version, 'namespace' => $this->namespace,
        ], JSON_THROW_ON_ERROR));
        $body = $fail
            ? "throw new \\PTAdmin\\Addon\\Exception\\AddonException('new provider failed', 'provider_test_failed');"
            : "\$this->app->instance('process-addon.version', '$version'); file_put_contents(storage_path('provider-version'), '$version');";
        file_put_contents($directory.'/src/Providers/ServiceProvider.php', "<?php\nnamespace {$this->namespace}\\Providers;\n"
            .'final class ServiceProvider extends \\Illuminate\\Support\\ServiceProvider implements \\PTAdmin\\Addon\\Runtime\\Contracts\\ProviderInterface {'
            .' public function register(): void { '.$body.' }}');
        return $directory;
    }

    private function diagnostic(string $taskId): string
    {
        return json_encode(app(TaskManager::class)->get($taskId), JSON_UNESCAPED_UNICODE)
            .(is_file($this->root.'/storage/test.log') ? file_get_contents($this->root.'/storage/test.log') : '');
    }
}
