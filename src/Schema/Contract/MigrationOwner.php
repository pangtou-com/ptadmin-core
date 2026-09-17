<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * 定义 Schema 迁移由 Schema 包执行还是由领域模块执行。
 */
final class MigrationOwner
{
    public const SCHEMA = 'schema';
    public const DOMAIN = 'domain';

    public static function normalize(?string $value): string
    {
        return $value === self::DOMAIN ? self::DOMAIN : self::SCHEMA;
    }
}
