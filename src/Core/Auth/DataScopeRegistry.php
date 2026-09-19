<?php

declare(strict_types=1);

namespace PTAdmin\Core\Auth;

use InvalidArgumentException;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Schema\Contract\ServerConstraint;

final class DataScopeRegistry
{
    /** @var array<string, DataScopeHandlerInterface> */
    private array $handlers = [];

    public function register(DataScopeHandlerInterface $handler): void
    {
        $type = $handler->type();
        if (preg_match('/^[a-z][a-z0-9._-]{0,99}$/', $type) !== 1) {
            throw new InvalidArgumentException('数据范围类型编码不合法：'.$type);
        }
        if (in_array($type, ['all', 'self'], true) || isset($this->handlers[$type])) {
            throw new InvalidArgumentException('数据范围类型已经注册：'.$type);
        }

        $this->handlers[$type] = $handler;
    }

    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /** @param array<string, mixed>|list<mixed>|null $value */
    public function validate(string $type, ?array $value): bool
    {
        return isset($this->handlers[$type]) && $this->handlers[$type]->validate($value);
    }

    /**
     * @param array<string, mixed>|list<mixed>|null $value
     * @param array<string, mixed> $schema
     */
    public function resolve(
        string $type,
        Admin $subject,
        string $resourceKey,
        string $action,
        ?array $value,
        array $schema,
    ): ?ServerConstraint {
        $handler = $this->handlers[$type] ?? null;

        return $handler?->resolve($subject, $resourceKey, $action, $value, $schema);
    }
}
