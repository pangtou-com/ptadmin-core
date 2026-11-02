<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PTAdmin\Admin\Http\Middleware\RecordAdminOperationMiddleware;
use PTAdmin\Admin\Navigation\MenuSynchronizer;
use PTAdmin\Core\Auth\Session\SessionManager;
use PTAdmin\Core\Providers\PTAdminServiceProvider;
use PTAdmin\Core\Tasks\TaskStore;

final class TaskHistoryHttpTest extends TestCase
{
    private string $storage;
    private array $headers;

    protected function getPackageProviders($app): array
    {
        return [PTAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->useStoragePath($this->storage);
        $app['config']->set('ptadmin.addon.path', $this->storage.'/addons');
        $app['config']->set('ptadmin.tasks.path', $this->storage.'/app/ptadmin/tasks');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $directory = $this->storage.'/app/ptadmin/install';
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/installed.json', json_encode([
            'status' => 'installed', 'core_version' => '0.1.0', 'web_prefix' => 'admin_test', 'installed_at' => gmdate('c'),
        ], JSON_THROW_ON_ERROR));
    }

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir().'/ptadmin-history-http-'.bin2hex(random_bytes(6));
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'testing', '--force' => true])->assertSuccessful();
        $this->withoutMiddleware(RecordAdminOperationMiddleware::class);
        $this->app->make(MenuSynchronizer::class)->syncCore();
        DB::table('admins')->insert([
            'id' => 1, 'username' => 'history-viewer', 'password' => 'unused',
            'status' => 1, 'is_founder' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = $this->app->make(SessionManager::class)->issue('admin', 1, 'admin')->toArray()['access_token'];
        $this->headers = ['Authorization' => 'Bearer '.$token];
        $this->app->make(TaskStore::class)->writeState('history-test', [
            'task_id' => 'history-test', 'type' => 'addon.install', 'status' => 'succeeded',
            'created_at' => '2020-01-01T00:00:00+00:00', 'updated_at' => '2020-01-01T00:00:00+00:00',
            'finished_at' => '2020-01-01T00:00:00+00:00',
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->storage);
    }

    public function testHistoryAndCleanupRequireAuthenticationAndSeparatePermissions(): void
    {
        $this->getJson('/ptadmin/tasks?scope=maintenance_history')->assertUnauthorized()->assertJsonPath('code', 40101);
        $this->postJson('/ptadmin/tasks/history/cleanup', [])->assertUnauthorized();
        $this->getJson('/ptadmin/tasks?scope=maintenance_history', $this->headers)->assertForbidden();
        $this->grant(['view']);
        $this->getJson('/ptadmin/tasks?scope=maintenance_history', $this->headers)
            ->assertOk()->assertJsonPath('code', 0)->assertJsonPath('data.total', 1)
            ->assertJsonCount(1, 'data.results')->assertJsonPath('data.active', [])
            ->assertJsonPath('data.has_more', false)->assertJsonPath('data.next_cursor', null);
        $this->postJson('/ptadmin/tasks/history/cleanup', [], $this->headers)
            ->assertForbidden()->assertJsonPath('data.ability', 'remove');
        self::assertFileExists($this->storage.'/app/ptadmin/tasks/history-test/state.json');
    }

    public function testCleanupWithoutParametersRemovesTerminalTasksAndKeepsActiveTasks(): void
    {
        $this->grant(['view', 'remove']);
        $store = $this->app->make(TaskStore::class);
        foreach (['pending', 'running', 'failed', 'blocked', 'cancelled'] as $status) {
            $id = 'history-'.$status;
            $store->writeState($id, [
                'task_id' => $id, 'type' => 'addon.install', 'status' => $status,
                'created_at' => gmdate('c'), 'updated_at' => gmdate('c'),
            ]);
        }
        $this->postJson('/ptadmin/tasks/history/cleanup', [], $this->headers)
            ->assertOk()->assertJsonPath('code', 0)->assertJsonPath('data.task_count', 4)
            ->assertJsonPath('data.protected_count', 2)
            ->assertJsonPath('data.removed_task_ids', ['history-blocked', 'history-cancelled', 'history-failed', 'history-test']);
        self::assertFileDoesNotExist($this->storage.'/app/ptadmin/tasks/history-test/state.json');
        foreach (['pending', 'running'] as $status) {
            self::assertFileExists($this->storage.'/app/ptadmin/tasks/history-'.$status.'/state.json');
        }
        $this->postJson('/ptadmin/tasks/history/cleanup', [], $this->headers)
            ->assertOk()->assertJsonPath('data.task_count', 0);
    }

    public function testHistoryRejectsInvalidInputUsingAdminEnvelope(): void
    {
        $this->grant(['view', 'remove']);
        foreach (['limit=101', 'status=running', 'cursor=bad-cursor'] as $query) {
            $this->getJson('/ptadmin/tasks?scope=maintenance_history&'.$query, $this->headers)
                ->assertStatus(422)->assertJsonPath('code', 42200);
        }
    }

    private function grant(array $abilities): void
    {
        DB::table('admin_grants')->where('subject_id', '1')->delete();
        DB::table('admin_grants')->insert([
            'subject_type' => 'admin', 'subject_id' => '1',
            'resource_id' => DB::table('admin_resources')->where('resource_key', 'core.tasks')->value('id'),
            'effect' => 'allow', 'abilities_json' => json_encode($abilities, JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
