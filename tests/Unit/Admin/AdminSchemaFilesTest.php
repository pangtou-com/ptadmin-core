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
        self::assertSame(['field' => 'owner_type', 'op' => 'eq', 'value' => 'custom'], $table['operate'][1]['show_when']);
        self::assertContains('owner_type', $table['fields']);
        $ownerType = $this->field($definition['fields'], 'owner_type');
        self::assertTrue($ownerType['readonly']);
        self::assertFalse($ownerType['formable']);
        self::assertSame(['core', 'addon', 'custom', 'unknown'], array_column($ownerType['options']['items'], 'value'));
        self::assertSame('dialog', $form['wrapper']['type']);
        self::assertSame('group', $this->field($definition['fields'], 'type')['default']);
        self::assertSame('page', $this->field($definition['fields'], 'type')['options']['items'][2]['value']);
        self::assertSame('页面', $this->field($definition['fields'], 'type')['options']['items'][2]['label']);
        self::assertSame('admin-menu-type-picker', $this->formField($form['children'], 'type')['component']);
        self::assertArrayNotHasKey('tip', $this->formField($form['children'], 'type'));
        foreach ($this->field($definition['fields'], 'type')['options']['items'] as $option) {
            self::assertNotEmpty($option['description']);
        }
        self::assertSame('parent_key', $this->field($definition['fields'], 'parent_key')['options']['reference']['parent_field']);
        self::assertTrue($this->formField($form['children'], 'parent_key')['props']['checkStrictly']);
        $icon = $this->formField($form['children'], 'icon');
        self::assertSame('icon-input', $icon['component']);
        self::assertContains('layout-dashboard', $icon['props']['icons']);
        self::assertArrayNotHasKey('component', (array) ($this->field($definition['fields'], 'icon')['extensions'] ?? []));
        self::assertSame('admin-schema-resource-picker', $this->formField($form['children'], 'target_resource_key')['component']);
        self::assertSame(['field' => 'type', 'op' => 'neq', 'value' => 'group'], $this->formField($form['children'], 'path')['show_when']);
        self::assertSame(['is_menu', 'status', 'sort'], array_column(array_slice($form['children'], -3), 'field'));
        self::assertSame(12, $this->formField($form['children'], 'is_menu')['span']);
        self::assertSame(12, $this->formField($form['children'], 'status')['span']);
        self::assertNotContains('page_key', $this->formFields($form['children']));
        self::assertNotContains('page_key', array_column($definition['fields'], 'name'));
        self::assertTrue($this->field($definition['fields'], 'owner_code')['listable']);
        self::assertTrue($this->field($definition['fields'], 'is_menu')['default']);
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

        self::assertSame(['update', 'sync_roles', 'reset_password', 'delete'], array_column($users['views']['tables']['default']['operate']['actions'], 'action'));
        self::assertSame(
            ['field' => 'is_founder', 'op' => 'neq', 'value' => true],
            $users['views']['tables']['default']['operate']['actions'][3]['show_when'],
        );
        self::assertNotContains('role_ids', $this->formFields($users['views']['forms']['default']['children']));
        self::assertContains('role_names', array_column($users['views']['tables']['default']['columns'], 'field'));
        $roleNames = $this->field($users['fields'], 'role_names');
        self::assertFalse($roleNames['persisted']);
        self::assertTrue($roleNames['readonly']);
        self::assertTrue($roleNames['listable']);
        $roleField = $this->field($users['fields'], 'role_ids');
        self::assertFalse($roleField['formable']);
        self::assertTrue($roleField['multiple']);
        self::assertSame(['resource' => 'admin.admin_roles', 'value_field' => 'id', 'label_field' => 'name'], $roleField['reference']);
        self::assertSame('reference', $roleField['options']['mode']);
        self::assertSame(['role_ids'], $this->formFields($users['views']['forms']['sync_roles']['children']));
        self::assertSame(['password', 'password_confirmation'], $this->formFields($users['views']['forms']['reset_password']['children']));
        self::assertSame('record', $users['actions']['sync_roles']['scope']);
        self::assertSame('record', $users['actions']['reset_password']['scope']);
        self::assertSame(
            ['field' => 'is_founder', 'op' => 'neq', 'value' => true],
            $users['views']['tables']['default']['operate']['actions'][1]['show_when'],
        );
        $avatarField = $this->formField($users['views']['forms']['default']['children'], 'avatar');
        self::assertSame('image-upload', $avatarField['component']);
        self::assertSame('url', $avatarField['props']['valueMode']);
        self::assertSame(
            ['username', 'nickname', 'password', 'email', 'mobile', 'avatar', 'status'],
            $this->formFields($users['views']['forms']['default']['children']),
        );
        self::assertArrayNotHasKey('title', $users['views']['forms']['default']['children'][0]);
        self::assertSame('昵称', $this->field($users['fields'], 'nickname')['label']);
        self::assertSame('用户名或昵称', $this->field($users['fields'], 'account_keyword')['label']);

        $rolePermissions = $this->field($roles['fields'], 'permissions');
        $userPermissions = $this->field($users['fields'], 'permissions');
        self::assertFalse($rolePermissions['formable']);
        self::assertFalse($userPermissions['formable']);
        self::assertArrayNotHasKey('component', (array) $rolePermissions['extensions']);
        self::assertArrayNotHasKey('component', (array) $userPermissions['extensions']);
    }

    public function testAuditLogsOnlyExposeReadAndDetailViews(): void
    {
        $directory = dirname(__DIR__, 3).'/database/schemas/admin';
        foreach (['admin.login_logs.schema.json', 'admin.operation_records.schema.json'] as $filename) {
            $schema = (new Loader())->resourceFromFile($directory.'/'.$filename)['schema']->toArray();
            $table = $schema['views']['tables']['default'];

            self::assertSame(['view'], array_column($table['operate']['actions'], 'action'));
            self::assertSame(['left' => [], 'right' => [], 'batch' => []], $table['toolbar']);
            self::assertFalse($table['selection']);
            self::assertSame(['table' => 'default', 'detail' => 'default'], $schema['views']['page']);
            self::assertContains('admin_name', array_column($table['columns'], 'field'));
            self::assertNotContains('admin_id', array_column($table['columns'], 'field'));
            self::assertFalse($this->field($schema['fields'], 'admin_id')['listable']);
            self::assertTrue($this->field($schema['fields'], 'admin_name')['listable']);
            self::assertFalse($schema['actions']['create']['enabled']);
            self::assertFalse($schema['actions']['update']['enabled']);
            self::assertFalse($schema['actions']['delete']['enabled']);
        }

        $operationSchema = (new Loader())->resourceFromFile($directory.'/admin.operation_records.schema.json')['schema']->toArray();
        self::assertContains('response_time_ms', array_column($operationSchema['views']['tables']['default']['columns'], 'field'));
        self::assertContains('response_time_ms', $this->formFields($operationSchema['views']['details']['default']['children']));
        self::assertContains('request_payload', $this->formFields($operationSchema['views']['details']['default']['children']));
        self::assertTrue($this->field($operationSchema['fields'], 'response_time_ms')['listable']);
        self::assertFalse($this->field($operationSchema['fields'], 'request_payload')['listable']);
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
