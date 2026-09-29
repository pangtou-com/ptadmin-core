<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use PTAdmin\Schema\Contract\ListQuery;
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

    public function testFilterSerializationKeepsFalseAndZeroValues(): void
    {
        $query = new ListQuery([
            'resource' => 'demo.records',
            'filters' => [
                ['field' => 'successful', 'operator' => 'eq', 'value' => false],
                ['field' => 'attempts', 'operator' => 'eq', 'value' => 0],
                ['field' => 'optional', 'operator' => 'eq', 'value' => null],
            ],
        ]);

        self::assertSame([
            ['field' => 'successful', 'operator' => 'eq', 'value' => false],
            ['field' => 'attempts', 'operator' => 'eq', 'value' => 0],
            ['field' => 'optional', 'operator' => 'eq'],
        ], $query->toPayloadArray()['filters']);
    }
}
