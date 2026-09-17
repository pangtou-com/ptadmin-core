<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * Schema 主链错误阶段常量。
 */
final class Stage
{
    public const RESOURCE = 'resource';
    public const DRAFT = 'draft';
    public const CHECKING = 'checking';
    public const MIGRATION = 'migration';
    public const ACTIVATING = 'activating';
    public const PROJECTION = 'projection';
    public const RUNTIME = 'runtime';
}
