<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Admin\Navigation\MenuSynchronizer;
use PTAdmin\Core\Providers\PTAdminServiceProvider;

final class MenuChildrenSynchronizationTest extends TestCase
{
    private string $root;
    private MenuSynchronizer $menus;

    protected function getPackageProviders($app): array
    {
        return [PTAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->useStoragePath($this->root.'/storage');
        $app['config']->set([
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'ptadmin.addon.path' => $this->root.'/addons',
        ]);
        $directory = $this->root.'/storage/app/ptadmin/install';
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/installed.json', json_encode([
            'status' => 'installed', 'core_version' => '0.1.0', 'web_prefix' => 'admin_test', 'installed_at' => gmdate('c'),
        ], JSON_THROW_ON_ERROR));
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-menu-children-'.bin2hex(random_bytes(6));
        parent::setUp();
        mkdir($this->root.'/resources', 0700, true);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->menus = new MenuSynchronizer(
            $this->app['db'], $this->app->make(AddonCatalog::class),
            $this->root.'/resources/menu.json', $this->root.'/addons', $this->root,
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->deleteDirectory($this->root);
    }

    #[DataProvider('owners')]
    public function testAllOwnersSupportNestedAndFlatMenusWithoutChangingPathsOrPermissions(string $ownerType, string $ownerCode, string $prefix): void
    {
        $article = [
            'resource_key' => 'demo.articles', 'type' => 'schema', 'is_menu' => true,
            'target_resource_key' => 'demo.articles', 'path' => $ownerType === 'core' ? '/articles' : 'articles',
            'abilities' => ['list', 'view'], 'sort' => 7,
            'meta' => ['settings' => ['z' => 2, 'a' => 1], 'items' => [['z' => 2, 'a' => 1], (object) []]],
        ];
        $this->write([
            $this->group('demo', [
                $article,
                $this->group('demo.settings', [[
                    'resource_key' => 'demo.settings.edit', 'type' => 'action', 'abilities' => ['update'],
                ]]),
            ]),
            ['resource_key' => 'demo.extra', 'type' => 'page', 'is_menu' => true, 'parent_key' => 'demo.settings',
                'path' => $ownerType === 'core' ? '/extra' : 'extra'],
        ]);

        $result = $this->sync($ownerType);
        self::assertSame(5, $result['created']);
        self::assertSame(['demo', 'demo.articles', 'demo.settings', 'demo.settings.edit', 'demo.extra'], $result['resource_keys']);
        self::assertSame(5, $this->sync($ownerType)['unchanged']);
        $resources = DB::table('admin_resources')->get()->keyBy('resource_key');
        self::assertSame('demo', $resources['demo.articles']->parent_key);
        self::assertSame('demo.settings', $resources['demo.settings.edit']->parent_key);
        self::assertSame('demo.settings', $resources['demo.extra']->parent_key);
        self::assertSame($prefix.'/articles', $resources['demo.articles']->path);
        self::assertSame($prefix.'/extra', $resources['demo.extra']->path);
        self::assertSame(7, $resources['demo.articles']->sort);
        self::assertSame(0, $resources['demo.settings.edit']->is_menu);
        $meta = json_decode($resources['demo.articles']->meta_json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($ownerType, $meta['owner_type']);
        self::assertSame($ownerCode, $meta['owner_code']);
        self::assertSame(['list', 'view'], $meta['abilities']);
        self::assertSame(['a' => 1, 'z' => 2], $meta['settings']);
        self::assertSame([['a' => 1, 'z' => 2], []], $meta['items']);
        self::assertArrayNotHasKey('children', $meta);
    }

    public static function owners(): iterable
    {
        yield 'Core' => ['core', 'ptadmin', ''];
        yield '宿主' => ['app', 'host', '/app'];
        yield '插件' => ['addon', 'demo', '/plugins/demo'];
    }

    public function testCloudMarketCannotBeDeclaredByAnApplication(): void
    {
        $this->write([['resource_key' => 'cloud.market', 'title' => '云市场', 'type' => 'action', 'abilities' => ['manage']]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('创始人专属固定功能');
        $this->menus->syncApp($this->root);
    }

    public function testCoreOperationsBelongToTheirFunctionsWithoutRegranting(): void
    {
        $menus = app(MenuSynchronizer::class);
        $menus->syncCore();
        $parents = [
            'admin.dashboard_widgets' => 'dashboard',
            'admin.notification_scenes' => 'admin.notification_templates',
            'admin.notice_channels' => 'admin.notification_templates',
            'admin.notification_messages' => 'admin.notification_templates',
            'admin.notification_deliveries' => 'admin.notification_templates',
            'admin.login_logs' => 'admin.logs', 'admin.operation_records' => 'admin.logs',
        ];
        $before = DB::table('admin_resources')->whereIn('resource_key', array_keys($parents))->get()->keyBy('resource_key');
        DB::table('admin_resources')->whereIn('resource_key', array_keys($parents))->update(['parent_key' => 'admin']);
        $grantId = DB::table('admin_grants')->insertGetId([
            'subject_type' => 'admin', 'subject_id' => '5', 'resource_id' => $before['admin.dashboard_widgets']->id,
            'abilities_json' => '["update"]', 'effect' => 'allow', 'scope_type' => 'all',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $grant = DB::table('admin_grants')->find($grantId);
        $menus->syncCore();
        foreach ($parents as $key => $parent) {
            $this->assertDatabaseHas('admin_resources', [
                'id' => $before[$key]->id, 'resource_key' => $key, 'parent_key' => $parent, 'meta_json' => $before[$key]->meta_json,
            ]);
        }
        self::assertEquals($grant, DB::table('admin_grants')->find($grantId));
        self::assertSame(0, $menus->syncCore()['updated']);
    }

    public function testTreeAndFlatFormsAreEquivalentAndRemovedChildrenAreDisabled(): void
    {
        $child = ['resource_key' => 'demo.page', 'type' => 'page', 'path' => 'page', 'is_menu' => true];
        $this->write([$this->group('demo', [$child]), $this->group('demo.other', [])]);
        $this->menus->syncApp($this->root);
        $id = DB::table('admin_resources')->where('resource_key', 'demo.page')->value('id');

        $this->write([$this->group('demo'), $child + ['parent_key' => 'demo'], $this->group('demo.other')]);
        self::assertSame(3, $this->menus->syncApp($this->root)['unchanged']);

        $this->write([$this->group('demo'), $this->group('demo.other', [$child + ['parent_key' => 'demo.other']])]);
        self::assertSame(1, $this->menus->syncApp($this->root)['updated']);
        $this->assertDatabaseHas('admin_resources', ['id' => $id, 'resource_key' => 'demo.page', 'parent_key' => 'demo.other']);

        $this->write([$this->group('demo'), $this->group('demo.other', [])]);
        $result = $this->menus->syncApp($this->root);
        self::assertSame(1, $result['disabled']);
        self::assertSame(['demo', 'demo.other'], $result['resource_keys']);
        $this->assertDatabaseHas('admin_resources', ['id' => $id, 'status' => 0, 'deleted_at' => null]);
    }

    #[DataProvider('invalidTrees')]
    public function testInvalidChildrenAndCrossLevelConflictsLeaveExistingResourcesUnchanged(array $definitions, string $message): void
    {
        $this->write([$this->group('demo')]);
        $this->menus->syncApp($this->root);
        $before = DB::table('admin_resources')->get()->toJson();
        $this->write($definitions);

        try {
            $this->menus->syncApp($this->root);
            self::fail('无效菜单树必须终止同步。');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
        self::assertSame($before, DB::table('admin_resources')->get()->toJson());
    }

    public static function invalidTrees(): iterable
    {
        $root = ['resource_key' => 'demo', 'type' => 'group', 'is_menu' => true];
        $child = ['resource_key' => 'demo.page', 'type' => 'page', 'path' => 'page', 'is_menu' => true];
        foreach (['空值' => null, '字符串' => 'invalid', '布尔值' => false, '空对象' => (object) [], '对象' => (object) ['page' => $child]] as $name => $children) {
            yield $name => [[$root + ['children' => $children]], 'children 必须是对象数组'];
        }
        yield '子项为数组' => [[$root + ['children' => [[]]]], '必须是对象'];
        yield '子项为字符串' => [[$root + ['children' => ['page']]], '必须是对象'];
        yield '父级冲突' => [[$root + ['children' => [$child + ['parent_key' => 'other']]]], 'parent_key 与 children 父级不一致'];
        yield '空父级冲突' => [[$root + ['children' => [$child + ['parent_key' => '']]]], 'parent_key 与 children 父级不一致'];
        yield '跨层重复编码' => [[$root + ['children' => [$child]], $child], '资源声明重复'];
        yield '跨分支重复编码' => [[$root + ['children' => [$child]], ['resource_key' => 'other', 'type' => 'group', 'children' => [$child]]], '资源声明重复'];
        yield '跨层重复路径' => [[$root + ['children' => [$child]], array_replace($child, ['resource_key' => 'demo.duplicate'])], '菜单路径重复'];
        yield '父级循环' => [[$root + ['parent_key' => 'demo.page', 'children' => [$child]]], '父级关系形成循环'];
        yield '外链作为导航父级' => [[['resource_key' => 'demo', 'type' => 'external', 'path' => '/external', 'external_url' => 'https://example.com', 'is_menu' => true, 'children' => [$child]]], '父级不能作为导航目录'];
    }

    public function testNestedOwnerConflictRollsBackChangesToEarlierNodes(): void
    {
        $this->write([$this->group('shared')]);
        $this->menus->syncCore();
        $this->write([$this->group('demo')]);
        $this->menus->syncApp($this->root);
        $before = DB::table('admin_resources')->get()->toJson();
        $this->write([array_replace($this->group('demo', [$this->group('shared')]), ['title' => '不应保存'])]);

        try {
            $this->menus->syncApp($this->root);
            self::fail('跨来源的子资源必须拒绝同步。');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('owner 冲突：shared', $exception->getMessage());
        }
        self::assertSame($before, DB::table('admin_resources')->get()->toJson());
    }

    private function write(array $definitions): void
    {
        file_put_contents($this->root.'/resources/menu.json', json_encode($definitions, JSON_THROW_ON_ERROR));
    }

    private function group(string $key, array $children = []): array
    {
        return ['resource_key' => $key, 'type' => 'group', 'is_menu' => true, 'children' => $children];
    }

    private function sync(string $ownerType): array
    {
        return match ($ownerType) {
            'core' => $this->menus->syncCore(),
            'app' => $this->menus->syncApp($this->root),
            'addon' => $this->menus->syncAddon($this->root, 'Demo'),
        };
    }
}
