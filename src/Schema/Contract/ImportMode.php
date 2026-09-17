<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * 导入检查与提交采用的错误处理模式。
 */
final class ImportMode
{
    /** 任一数据错误都会阻止整批提交。 */
    public const STRICT = 'strict';

    /** 允许跳过错误数据并提交其余有效记录。 */
    public const LENIENT = 'lenient';
}
