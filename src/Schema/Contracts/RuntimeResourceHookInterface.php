<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contracts;

use PTAdmin\Schema\Definition\Schema;

/**
 * 代码资源的受控运行时扩展点，用于密码哈希、关联同步和领域保护等 Schema 无法表达的规则。
 */
interface RuntimeResourceHookInterface
{
    /** @return list<string> */
    public function resources(): array;

    /** @param array<string, mixed> $input @param array<string, mixed> $prepared @return array<string, mixed> */
    public function prepareCreate(string $resourceKey, array $input, array $prepared, Schema $schema): array;

    /** @param array<string, mixed> $input */
    public function created(string $resourceKey, int $id, array $input, Schema $schema): void;

    /** @param array<string, mixed> $input @param array<string, mixed> $prepared @return array<string, mixed> */
    public function prepareUpdate(string $resourceKey, int $id, array $input, array $prepared, Schema $schema): array;

    /** @param array<string, mixed> $input */
    public function updated(string $resourceKey, int $id, array $input, Schema $schema): void;

    public function deleting(string $resourceKey, int $id, Schema $schema): void;

    public function deleted(string $resourceKey, int $id, Schema $schema): void;

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function output(string $resourceKey, array $row, Schema $schema): array;

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function executeAction(string $resourceKey, string $action, array $input, Schema $schema): array;
}
