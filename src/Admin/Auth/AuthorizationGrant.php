<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth;

/**
 * 已完成动作、数据范围和附加条件归一化的授权记录。
 */
final class AuthorizationGrant
{
    /**
     * @param list<string> $abilities
     * @param array<string, mixed>|list<mixed>|null $scopeValue
     * @param array<string, mixed>|list<mixed>|null $conditions
     */
    public function __construct(
        public readonly int $id,
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly string $effect,
        public readonly array $abilities,
        public readonly int $priority,
        public readonly ?string $scopeType,
        public readonly ?array $scopeValue,
        public readonly ?array $conditions,
    ) {
    }
}
