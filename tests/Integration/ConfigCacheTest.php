<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use PTAdmin\Core\Config\ConfigService;
use PTAdmin\Core\Providers\PTAdminServiceProvider;

final class ConfigCacheTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PTAdminServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['2026_09_15_000005_create_ptadmin_config_tables.php', '2026_09_21_000001_add_config_revision.php',
            '2026_09_21_000002_add_config_definition_metadata.php', '2026_09_30_000001_add_config_definition_icon.php'] as $file) {
            (require __DIR__.'/../../database/migrations/'.$file)->up();
        }
        app(ConfigService::class)->syncDefinition([
            'owner_type' => 'addon', 'owner_code' => 'test', 'config_key' => 'options', 'group_code' => 'addon', 'title' => '配置',
            'schema' => ['name' => 'options', 'fields' => [
                ['name' => 'label', 'type' => 'string', 'default' => '默认值'],
                ['name' => 'secret', 'type' => 'string', 'extensions' => ['secret' => ['storage_mode' => 'encrypted', 'readable' => false]]],
            ]],
        ]);
    }

    public function testMissingValueIsCachedAndFirstSaveInvalidatesIt(): void
    {
        Cache::flush();
        DB::enableQueryLog();
        for ($index = 0; $index < 100; $index++) {
            $result = app(ConfigService::class)->get('addon', 'test', 'options');
            self::assertSame('默认值', $result['effective_values']['label']);
            self::assertSame(0, $result['revision']);
            self::assertSame([], $result['overrides']);
            self::assertNull($result['updated_at']);
        }
        self::assertCount(1, array_filter(DB::getQueryLog(), static fn (array $q): bool => str_contains($q['query'], 'ptadmin_config_values')));
        $configs = app(ConfigService::class);
        $configs->put('addon', 'test', 'options', ['label' => '已保存', 'secret' => 'private-value'], expectedRevision: 0);
        $result = $configs->get('addon', 'test', 'options');
        self::assertSame('已保存', $result['effective_values']['label']);
        self::assertSame(1, $result['revision']);
        self::assertArrayNotHasKey('secret', $result['effective_values']);
        self::assertStringNotContainsString('private-value', json_encode($result));
        self::assertSame('private-value', $configs->secretValue('options.secret', 'addon', 'test'));
    }

    public function testUncommittedValuesNeverEscapeRollback(): void
    {
        $configs = app(ConfigService::class);
        self::assertSame('默认值', $configs->get('addon', 'test', 'options')['effective_values']['label']);
        DB::beginTransaction();
        try {
            $configs->put('addon', 'test', 'options', ['label' => '未提交']);
            self::assertSame('未提交', $configs->get('addon', 'test', 'options')['effective_values']['label']);
        } finally {
            DB::rollBack();
        }
        self::assertSame('默认值', $configs->get('addon', 'test', 'options')['effective_values']['label']);
        self::assertSame(0, $configs->get('addon', 'test', 'options')['revision']);
        DB::transaction(fn () => $configs->put('addon', 'test', 'options', ['label' => '已提交']));
        self::assertSame('已提交', $configs->get('addon', 'test', 'options')['effective_values']['label']);
    }
}
