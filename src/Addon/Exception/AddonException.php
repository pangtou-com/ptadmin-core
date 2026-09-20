<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Exception;

use RuntimeException;

/**
 * Addon 边界的可诊断异常。
 *
 * errorCode 供任务、HTTP 和生命周期诊断稳定识别错误类型，details 只承载可安全向上层传递的结构化上下文。
 */
final class AddonException extends RuntimeException
{
    /**
     * @param string $errorCode 跨调用层保持稳定的机器错误码
     * @param array<string, mixed> $details 与失败对象相关的结构化信息，例如插件编码、文件或资源编码
     */
    public function __construct(string $message, public string $errorCode = 'invalid_addon_manifest', public array $details = [])
    {
        parent::__construct($message);
    }
}
