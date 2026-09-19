<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Auth;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Core\Auth\DataScopeHandlerInterface;
use PTAdmin\Core\Auth\DataScopeRegistry;
use PTAdmin\Schema\Contract\ServerConstraint;

final class DataScopeRegistryTest extends TestCase
{
    public function testRegisteredHandlerValidatesAndResolvesItsOwnScope(): void
    {
        $registry = new DataScopeRegistry();
        $registry->register($this->handler('category'));
        $admin = new Admin();
        $admin->setAttribute('id', 42);

        self::assertTrue($registry->has('category'));
        self::assertTrue($registry->validate('category', ['ids' => [3, 5]]));
        self::assertFalse($registry->validate('category', ['ids' => []]));
        self::assertFalse($registry->validate('missing', ['ids' => [3]]));
        $constraint = $registry->resolve('category', $admin, 'cms.articles', 'list', ['ids' => [3, 5]], []);
        self::assertInstanceOf(ServerConstraint::class, $constraint);
        self::assertSame([
            'field' => 'category_id',
            'operator' => 'in',
            'value' => [3, 5],
        ], $constraint->toArray());
        self::assertNull($registry->resolve('missing', $admin, 'cms.articles', 'list', ['ids' => [3]], []));
    }

    /** @dataProvider invalidTypeProvider */
    public function testReservedAndInvalidTypesCannotBeRegistered(string $type): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DataScopeRegistry())->register($this->handler($type));
    }

    public function testTypeCannotBeRegisteredTwice(): void
    {
        $registry = new DataScopeRegistry();
        $registry->register($this->handler('category'));

        $this->expectException(InvalidArgumentException::class);
        $registry->register($this->handler('category'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTypeProvider(): iterable
    {
        yield 'reserved all' => ['all'];
        yield 'reserved self' => ['self'];
        yield 'uppercase' => ['Category'];
        yield 'leading digit' => ['1category'];
        yield 'empty' => [''];
    }

    private function handler(string $type): DataScopeHandlerInterface
    {
        return new class($type) implements DataScopeHandlerInterface {
            public function __construct(private string $scopeType)
            {
            }

            public function type(): string
            {
                return $this->scopeType;
            }

            public function validate(?array $value): bool
            {
                return isset($value['ids']) && is_array($value['ids']) && $value['ids'] !== [];
            }

            public function resolve(
                Admin $subject,
                string $resourceKey,
                string $action,
                ?array $value,
                array $schema,
            ): ?ServerConstraint {
                return new ServerConstraint([
                    'field' => 'category_id',
                    'operator' => 'in',
                    'value' => $value['ids'] ?? [],
                ]);
            }
        };
    }
}
