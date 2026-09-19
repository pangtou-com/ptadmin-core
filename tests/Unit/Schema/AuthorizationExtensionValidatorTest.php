<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use PTAdmin\Schema\Definition\Schema;

final class AuthorizationExtensionValidatorTest extends TestCase
{
    public function testOwnerFieldMayReferenceAPersistedField(): void
    {
        $issues = (new Schema($this->schema([
            'name' => 'created_by',
            'type' => 'integer',
            'label' => '创建管理员',
            'persisted' => true,
        ])))->validate();

        self::assertSame([], $issues);
    }

    /** @dataProvider invalidOwnerFieldProvider */
    public function testOwnerFieldMustReferenceAPersistedField(array $field): void
    {
        $issues = (new Schema($this->schema($field)))->validate();

        self::assertNotEmpty(array_filter(
            $issues,
            static fn (array $issue): bool =>
                $issue['code'] === 'invalid_extension'
                && $issue['path'] === 'extensions.authorization.owner_field',
        ));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidOwnerFieldProvider(): iterable
    {
        yield 'different field' => [[
            'name' => 'author_id',
            'type' => 'integer',
            'label' => '作者',
            'persisted' => true,
        ]];
        yield 'virtual field' => [[
            'name' => 'created_by',
            'type' => 'integer',
            'label' => '创建管理员',
            'persisted' => false,
        ]];
    }

    /** @param array<string, mixed> $field @return array<string, mixed> */
    private function schema(array $field): array
    {
        return [
            'name' => 'articles',
            'title' => '文章',
            'fields' => [$field],
            'extensions' => [
                'authorization' => ['owner_field' => 'created_by'],
            ],
        ];
    }
}
