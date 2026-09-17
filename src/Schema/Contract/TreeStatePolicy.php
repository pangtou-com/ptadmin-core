<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * 树形查询应用状态过滤时的结构策略。
 */
final class TreeStatePolicy
{
    /** 不启用树形状态过滤。 */
    public const NONE = '';

    /** 只返回匹配状态的节点，不补齐未匹配的祖先路径。 */
    public const FILTER = 'filter';
}
