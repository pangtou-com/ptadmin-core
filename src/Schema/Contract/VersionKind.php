<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * 版本引用的语义类型，用于区分当前草稿版本与当前激活版本。
 */
final class VersionKind
{
    public const DRAFT = 'draft';
    public const ACTIVE = 'active';
}
