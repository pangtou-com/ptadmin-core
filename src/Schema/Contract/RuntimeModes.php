<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * Runtime 写入模式和回收站查询模式常量。
 */
final class RuntimeModes
{
    public const WRITE_DRAFT = 'draft';
    public const WRITE_SUBMIT = 'submit';
    public const RECYCLE_NORMAL = '';
    public const RECYCLE_ONLY = 'only';
    public const RECYCLE_WITH = 'with';
}
