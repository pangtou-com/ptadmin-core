<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PTAdmin\Schema\SchemaFile\Loader;

final class AdminSchemaFilesTest extends TestCase
{
    /** @var array<string, array{table:string,name:string}> */
    private const RESOURCES = [
        'admin.dashboard_widgets' => ['table' => 'admin_dashboard_widgets', 'name' => 'dashboard_widgets'],
        'admin.login_logs' => ['table' => 'admin_login_logs', 'name' => 'login_logs'],
        'admin.admin_resources' => ['table' => 'admin_resources', 'name' => 'admin_resources'],
        'admin.admin_roles' => ['table' => 'admin_roles', 'name' => 'admin_roles'],
        'admin.admin_users' => ['table' => 'admins', 'name' => 'admin_users'],
        'admin.notification_deliveries' => ['table' => 'notice_deliveries', 'name' => 'notice_deliveries'],
        'admin.notification_messages' => ['table' => 'notice_messages', 'name' => 'notice_messages'],
        'admin.notification_scenes' => ['table' => 'notice_scenes', 'name' => 'notice_scenes'],
        'admin.notification_templates' => ['table' => 'notice_templates', 'name' => 'notice_templates'],
        'admin.notice_channels' => ['table' => 'notice_channels', 'name' => 'notice_channels'],
        'admin.operation_records' => ['table' => 'admin_operation_records', 'name' => 'operation_records'],
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
            self::assertSame(self::RESOURCES[$resourceKey]['table'], $definition['table_name']);
            self::assertSame(self::RESOURCES[$resourceKey]['name'], $definition['schema']->name);
            self::assertSame('domain', $definition['migration_owner']);
            self::assertSame(['resource', 'table_name', 'migration_owner', 'schema'], array_keys($definition['metadata']));
            self::assertSame([], $definition['schema']->validate());
        }
    }

    public function testMenuManagementUsesTheGenericTreeResourcePage(): void
    {
        $path = dirname(__DIR__, 3).'/database/schemas/admin/admin.admin_resources.schema.json';
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

    public function testRolePermissionsUseASeparateRecordAction(): void
    {
        $directory = dirname(__DIR__, 3).'/database/schemas/admin';
        $roles = (new Loader())->resourceFromFile($directory.'/admin.admin_roles.schema.json')['schema']->toArray();
        $users = (new Loader())->resourceFromFile($directory.'/admin.admin_users.schema.json')['schema']->toArray();

        self::assertSame(
            ['update', 'sync_permissions', 'delete'],
            array_column($roles['views']['tables']['default']['operate']['actions'], 'action'),
        );
        self::assertSame('record', $roles['actions']['sync_permissions']['scope']);
        self::assertSame('custom', $roles['actions']['sync_permissions']['kind']);
        self::assertNotContains('permissions', $this->formFields($roles['views']['forms']['default']['children']));
        self::assertNotContains('permissions', $this->formFields($users['views']['forms']['default']['children']));

        self::assertSame(['update', 'delete'], array_column($users['views']['tables']['default']['operate']['actions'], 'action'));
        self::assertSame(
            ['field' => 'is_founder', 'op' => 'neq', 'value' => true],
            $users['views']['tables']['default']['operate']['actions'][1]['show_when'],
        );
        $roleField = $this->formField($users['views']['forms']['default']['children'], 'role_ids');
        self::assertNotSame([], $roleField);
        self::assertTrue($this->field($users['fields'], 'role_ids')['multiple']);
        self::assertSame(
            ['field' => 'is_founder', 'op' => 'eq', 'value' => true],
            $roleField['disabled_when'],
        );

        $rolePermissions = $this->field($roles['fields'], 'permissions');
        $userPermissions = $this->field($users['fields'], 'permissions');
        self::assertFalse($rolePermissions['formable']);
        self::assertFalse($userPermissions['formable']);
        self::assertArrayNotHasKey('component', (array) $rolePermissions['extensions']);
        self::assertArrayNotHasKey('component', (array) $userPermissions['extensions']);
    }

    /** @param list<array<string, mixed>> $nodes @return list<string> */
    private function formFields(array $nodes): array
    {
        $fields = [];
        foreach ($nodes as $node) {
            if (isset($node['field'])) {
                $fields[] = (string) $node['field'];
            }
            if (isset($node['children']) && is_array($node['children'])) {
                $fields = array_merge($fields, $this->formFields($node['children']));
            }
        }

        return $fields;
    }

    /** @param list<array<string, mixed>> $fields @return array<string, mixed> */
    private function field(array $fields, string $name): array
    {
        foreach ($fields as $field) {
            if (($field['name'] ?? null) === $name) {
                return $field;
            }
        }

        self::fail('Schema field not found: '.$name);
    }

    /** @param list<array<string, mixed>> $nodes @return array<string, mixed> */
    private function formField(array $nodes, string $name): array
    {
        foreach ($nodes as $node) {
            if (($node['field'] ?? null) === $name) {
                return $node;
            }
            if (isset($node['children']) && is_array($node['children'])) {
                $found = $this->formField($node['children'], $name);
                if ($found !== []) {
                    return $found;
                }
            }
        }

        return [];
    }
}
