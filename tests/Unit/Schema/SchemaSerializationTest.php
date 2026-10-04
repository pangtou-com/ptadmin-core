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

    public function testEmptySchemaObjectsSurviveAssociativeJsonStorageRoundTrip(): void
    {
        $schema = new Schema(['name' => 'empty', 'title' => '空模型', 'fields' => []]);
        $stored = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $restored = new Schema($stored);

        self::assertSame($schema->checksum(), $restored->checksum());
        $json = json_decode(json_encode($restored, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $json->fields);
        foreach (['capabilities', 'constraints', 'views'] as $property) {
            self::assertInstanceOf(\stdClass::class, $json->{$property});
            self::assertSame([], get_object_vars($json->{$property}));
        }
    }

    public function testResourceExtensionsChangeChecksumAndKeepUnknownValues(): void
    {
        $first = new Schema(['name' => 'example', 'title' => '示例', 'fields' => [], 'extensions' => [
            'custom' => ['zero' => 0, 'disabled' => false, 'empty' => [], 'object' => (object) []],
        ]]);
        $second = new Schema(['name' => 'example', 'title' => '示例', 'fields' => [], 'extensions' => [
            'custom' => ['object' => (object) [], 'empty' => [], 'disabled' => false, 'zero' => 0],
        ]]);
        self::assertSame($first->checksum(), $second->checksum());
        self::assertStringContainsString('"empty":[]', \PTAdmin\Schema\Support\Protocol::schemaJson($first->toArray()));
        self::assertStringContainsString('"object":{}', \PTAdmin\Schema\Support\Protocol::schemaJson($first->toArray()));
        $changed = $second->toArray();
        $changed['extensions']['custom']['zero'] = 1;
        self::assertNotSame($first->checksum(), (new Schema($changed))->checksum());
    }

    public function testResourceExtensionsMustBeObjects(): void
    {
        $schema = new Schema(['name' => 'example', 'title' => '示例', 'fields' => [], 'extensions' => ['cms' => ['invalid-list']]]);
        self::assertSame('extensions.cms', $schema->validate()[0]['path']);
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
