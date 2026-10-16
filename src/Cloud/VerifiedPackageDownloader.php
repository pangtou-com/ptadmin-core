<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

use PTAdmin\Cloud\Contracts\CloudTransportInterface;

/** 两种交付凭据共用下载完整性与原子落盘边界。 */
final class VerifiedPackageDownloader
{
    public function __construct(private readonly CloudTransportInterface $transport) {}

    public function download(string $accessToken, array $ticket, string $destination, string $path = '/licenses/download'): array
    {
        if (!is_string($ticket['ticket'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $ticket['ticket']) !== 1
            || !is_string($ticket['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $ticket['sha256']) !== 1
            || !is_int($ticket['size'] ?? null) || $ticket['size'] < 1) {
            throw new CloudException('平台下载凭证响应无效。', 'cloud_response_invalid');
        }
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0700, true) && !is_dir(dirname($destination))) {
            throw new CloudException('无法创建下载目录。', 'download_failed');
        }
        $temporary = $destination.'.'.bin2hex(random_bytes(6)).'.tmp';
        try {
            $this->transport->download($accessToken, $ticket['ticket'], $temporary, $ticket['size'], $path);
            if (!is_file($temporary) || filesize($temporary) !== $ticket['size'] || !hash_equals($ticket['sha256'], hash_file('sha256', $temporary))) {
                throw new CloudException('下载包大小或 SHA-256 不匹配。', 'checksum_mismatch');
            }
            chmod($temporary, 0600);
            if (!rename($temporary, $destination)) {
                throw new CloudException('无法保存下载包。', 'download_failed');
            }
            return ['artifact' => $destination, 'sha256' => $ticket['sha256'], 'size' => $ticket['size']];
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
