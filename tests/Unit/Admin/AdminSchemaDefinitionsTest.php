<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PTAdmin\Admin\Schema\AdminSchemaDefinitions;
use PTAdmin\Schema\Definition\Schema;

final class AdminSchemaDefinitionsTest extends TestCase
{
    public function testMenuManagementUsesTheGenericTreeResourcePage(): void
    {
        $definition = (new AdminSchemaDefinitions())->all()['admin.management_menus']['schema'];
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

        $serialized = (new Schema($definition))->toArray();
        self::assertFalse($serialized['views']['searches']);
        self::assertSame([], $serialized['views']['tables']['default']['toolbar']['batch']);
        self::assertFalse($serialized['views']['tables']['default']['selection']);
    }
}
