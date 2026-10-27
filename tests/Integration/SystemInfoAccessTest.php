<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Admin\Navigation\MenuSynchronizer;
use PTAdmin\Core\Auth\Session\SessionManager;
use PTAdmin\Core\Providers\PTAdminServiceProvider;
use PTAdmin\Core\Tasks\RunTaskAfterResponse;
use PTAdmin\Core\Tasks\TaskManager;

final class SystemInfoAccessTest extends TestCase
{
    private string $storage;
    private array $headers;
    private Admin $admin;

    protected function getPackageProviders($app): array
    {
        return [PTAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->useStoragePath($this->storage);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('ptadmin.addon.path', $this->storage.'/addons');
        $app['config']->set('ptadmin.tasks.path', $this->storage.'/app/ptadmin/tasks');
        $directory = $this->storage.'/app/ptadmin/install';
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/installed.json', json_encode([
            'status' => 'installed', 'core_version' => '0.1.0', 'web_prefix' => 'admin_test', 'installed_at' => gmdate('c'),
        ], JSON_THROW_ON_ERROR));
    }

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir().'/ptadmin-system-info-'.bin2hex(random_bytes(6));
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        app(MenuSynchronizer::class)->syncCore();
        $this->admin = Admin::query()->forceCreate([
            'username' => 'system-viewer', 'nickname' => '查看人员', 'password' => 'unused', 'status' => 1, 'is_founder' => false,
        ]);
        foreach (['dashboard', 'core.update', 'admin.frontend'] as $key) {
            $resourceId = DB::table('admin_resources')->where('resource_key', $key)->value('id');
            if ($resourceId === null) {
                $resourceId = DB::table('admin_resources')->insertGetId([
                    'resource_key' => $key, 'title' => $key, 'type' => 'action', 'status' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('admin_grants')->insert([
                'subject_type' => 'admin', 'subject_id' => (string) $this->admin->id, 'resource_id' => $resourceId,
                'effect' => 'allow', 'abilities_json' => '["view","create","update"]',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $token = app(SessionManager::class)->issue('admin', $this->admin->id, 'admin')->toArray()['access_token'];
        $this->headers = ['Authorization' => 'Bearer '.$token];
        Bus::fake();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->storage);
    }

    public function testNonFounderCanViewButCannotCheckEvenWithDashboardPermission(): void
    {
        $this->getJson('/ptadmin/dashboard', $this->headers)->assertOk()->assertJsonPath('code', 0);
        $this->postJson('/ptadmin/dashboard/application-sync', [], $this->headers)
            ->assertForbidden()->assertJsonPath('code', 40300)->assertJsonPath('message', '只有创始人可以检查系统更新。');
        $this->postJson('/ptadmin/dashboard/application-sync')->assertUnauthorized();
    }

    public function testFounderCanCheckAndRevokingFounderStatusTakesEffectForExistingSession(): void
    {
        $this->admin->forceFill(['is_founder' => true])->save();
        $this->postJson('/ptadmin/dashboard/application-sync', [], $this->headers)
            ->assertOk()->assertJsonPath('data.application_sync_status', 'success');
        $this->admin->forceFill(['is_founder' => false])->save();
        $this->postJson('/ptadmin/dashboard/application-sync', [], $this->headers)->assertForbidden();
    }

    public function testNonFounderCannotCreateRetryOrCancelSystemUpdatesDespiteExplicitGrants(): void
    {
        $tasks = app(TaskManager::class);
        foreach ([TaskManager::CORE_UPDATE, TaskManager::ADMIN_FRONTEND_UPDATE] as $type) {
            $before = $tasks->list();
            $this->postJson('/ptadmin/tasks', ['type' => $type, 'payload' => []], $this->headers)
                ->assertForbidden()->assertJsonPath('code', 40300);
            self::assertSame($before, $tasks->list());
            $task = $tasks->create($type);
            $id = $task['task_id'];
            $this->postJson('/ptadmin/tasks/'.$id.'/cancel', [], $this->headers)->assertForbidden();
            self::assertSame('pending', $tasks->get($id)['status']);
            $this->getJson('/ptadmin/tasks/'.$id, $this->headers)->assertOk()->assertJsonPath('data.status', 'pending');
            Bus::assertNotDispatchedAfterResponse(RunTaskAfterResponse::class);
            $tasks->start($id);
            $tasks->fail($id, ['message' => 'test failure']);
            $this->postJson('/ptadmin/tasks/'.$id.'/retry', [], $this->headers)->assertForbidden();
            self::assertSame('failed', $tasks->get($id)['status']);
        }
    }

    public function testFounderCanSubmitAndCancelFrontendUpdateWithoutRunningTheUpdater(): void
    {
        $this->admin->forceFill(['is_founder' => true])->save();
        $response = $this->postJson('/ptadmin/tasks', ['type' => TaskManager::ADMIN_FRONTEND_UPDATE, 'payload' => []], $this->headers)
            ->assertCreated()->assertJsonPath('data.status', 'pending');
        Bus::assertDispatchedAfterResponse(RunTaskAfterResponse::class);
        $this->postJson('/ptadmin/tasks/'.$response->json('data.task_id').'/cancel', [], $this->headers)
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    }
}
