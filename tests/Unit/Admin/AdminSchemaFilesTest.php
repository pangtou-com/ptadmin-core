<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PTAdmin\Schema\SchemaFile\Loader;

final class AdminSchemaFilesTest extends TestCase
{
    /** @var array<string, string> */
    private const RESOURCES = [
        'admin.dashboard_widgets' => 'admin_dashboard_widgets',
        'admin.login_logs' => 'admin_login_logs',
        'admin.management_menus' => 'admin_resources',
        'admin.management_roles' => 'admin_roles',
        'admin.management_users' => 'admins',
        'admin.notification_deliveries' => 'notification_deliveries',
        'admin.notification_messages' => 'notification_messages',
        'admin.notification_receipts' => 'notification_receipts',
        'admin.notification_scenes' => 'notification_scenes',
        'admin.notification_templates' => 'notification_templates',
        'admin.operation_records' => 'admin_operation_records',
    ];

    public function testAdminResourcesAreValidBuiltinSchemaFiles(): void
    {
        $loader = new Loader();
        $directory = dirname(__DIR__, 3).'/database/schemas/admin';
        $paths = glob($directory.'/*.schema.json');
        self::assertIsArray($paths);
        sort($paths);

        self::assertCount(count(self::RESOURCES), $paths);
        foreach ($paths as $path) {
            $definition = $loader->resourceFromFile($path);
            $resourceKey = $definition['resource'];

            self::assertArrayHasKey($resourceKey, self::RESOURCES);
            self::assertSame($resourceKey.'.schema.json', basename($path));
            self::assertSame(self::RESOURCES[$resourceKey], $definition['table_name']);
            self::assertSame('domain', $definition['migration_owner']);
            self::assertSame(['resource', 'table_name', 'migration_owner', 'schema'], array_keys($definition['metadata']));
            self::assertSame([], $definition['schema']->validate());
        }
    }

    public function testMenuManagementUsesTheGenericTreeResourcePage(): void
    {
        $path = dirname(__DIR__, 3).'/database/schemas/admin/admin.management_menus.schema.json';
        $definition = (new Loader())->resourceFromFile($path)['schema']->toArray();
        $page = $definition['views']['page'];
        $table = $definition['views']['tables']['default'];
        $form = $definition['views']['forms']['default'];

        self::assertSame([
            'table' => 'default',
            'createForm' => 'default',
            'updateForm' => 'default',
        ], $page);
        self::assertSame('tree', $table['mode']);
        self::assertSame([
            'enabled' => true,
            'key_field' => 'resource_key',
            'parent_field' => 'parent_key',
            'order_field' => 'sort',
        ], $definition['tree']);
        self::assertFalse($definition['views']['searches']);
        self::assertSame(['create', 'reload'], [
            $table['toolbar']['left'][0]['action'],
            $table['toolbar']['right'][0]['action'],
        ]);
        self::assertSame([], $table['toolbar']['batch']);
        self::assertFalse($table['selection']);
        self::assertSame(['update', 'delete'], array_column($table['operate'], 'action'));
        self::assertSame('dialog', $form['wrapper']['type']);
        self::assertArrayNotHasKey('menu.select', $definition['actions']);
    }
}
