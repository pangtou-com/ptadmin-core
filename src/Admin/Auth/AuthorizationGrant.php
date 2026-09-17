<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth;

/**
 * 已完成角色默认范围和租户关系归一化的授权记录。
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
        public readonly ?int $tenantId,
        public readonly ?string $scopeType,
        public readonly ?array $scopeValue,
        public readonly ?array $conditions,
    ) {
    }
}
