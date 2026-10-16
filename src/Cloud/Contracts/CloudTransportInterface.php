<?php

declare(strict_types=1);

namespace PTAdmin\Cloud\Contracts;

/** 隔离远程 HTTP 与平台宿主内的服务调用，凭据始终保留在服务端。 */
interface CloudTransportInterface
{
    public function request(string $method, string $path, array $payload = [], ?string $token = null): array;

    /** 将票据对应的私有包写入临时文件，调用方继续校验大小和 SHA-256。 */
    public function download(string $token, string $ticket, string $destination, int $maxBytes, string $path = '/licenses/download'): void;
}
