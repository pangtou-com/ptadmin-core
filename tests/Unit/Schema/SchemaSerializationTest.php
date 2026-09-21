<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use PTAdmin\Schema\Definition\Schema;

final class SchemaSerializationTest extends TestCase
{
    public function testEmptyFieldsRemainPresentInSerializedSchema(): void
    {
        $schema = new Schema([
            'name' => 'settings',
            'title' => 'Settings',
            'fields' => [],
        ]);

        self::assertSame([], $schema->toArray()['fields']);
    }
}
