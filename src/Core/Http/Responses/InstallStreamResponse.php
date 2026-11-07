<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Responses;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** 安装进度在实际 HTTP 发送时解除 PHP 缓冲，避免阶段日志积压到安装结束。 */
final class InstallStreamResponse extends StreamedResponse
{
    public function send(bool $flush = true): static
    {
        // 必须在响应头和正文发送前关闭压缩；单次 ob_flush 只能刷新最内层缓冲。
        @ini_set('zlib.output_compression', '0');
        $this->sendHeaders();
        self::closeOutputBuffers(0, true);

        return parent::send($flush);
    }
}
