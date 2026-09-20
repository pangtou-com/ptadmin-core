<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Frontend;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

/** 本地开发入口独立于插件签名包和正式发布目录，生产环境始终忽略。 */
final class FrontendDevelopmentStore
{
    /** @param array<int, string> $allowedOrigins */
    public function __construct(
        private string $path,
        private string $environment,
        private array $allowedOrigins = [],
        private int $leaseSeconds = 120,
    ) {
        $this->path = rtrim($path, '/');
    }

    /** @return array<string, mixed>|null */
    public function resolve(string $code): ?array
    {
        if (!$this->enabled()) {
            return null;
        }
        $this->assertCode($code);
        $path = $this->registrationPath($code);
        if (!is_file($path)) {
            return null;
        }
        try {
            $registration = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException(sprintf('插件 %s 的前端开发注册文件不是有效 JSON。', $code));
        }
        if (!is_array($registration) || array_is_list($registration) || ($registration['code'] ?? null) !== $code) {
            throw new RuntimeException(sprintf('插件 %s 的前端开发注册身份无效。', $code));
        }
        $expiresAt = DateTimeImmutable::createFromFormat(DATE_ATOM, (string) ($registration['expires_at'] ?? ''));
        $this->assertUrl((string) ($registration['entry_url'] ?? ''), (string) ($registration['allowed_origin'] ?? ''));
        $registration['status'] = $expiresAt && $expiresAt > new DateTimeImmutable() ? 'available' : 'expired';

        return $registration;
    }

    /** @return array<string, mixed> */
    public function register(string $code, string $entryUrl, string $sandbox, ?string $revision = null, ?int $processId = null): array
    {
        if (!$this->enabled()) {
            throw new RuntimeException('插件前端开发注册只允许在 local/testing 环境使用。');
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,63}$/', $code) !== 1) {
            throw new RuntimeException('插件编码格式无效。');
        }
        if (!in_array($sandbox, ['trusted', 'iframe'], true)) {
            throw new RuntimeException('插件前端开发 sandbox 只能是 trusted 或 iframe。');
        }
        $origin = $this->origin($entryUrl);
        $this->assertUrl($entryUrl, $origin);
        $now = new DateTimeImmutable();
        $registration = [
            'code' => $code,
            'entry_url' => $entryUrl,
            'allowed_origin' => $origin,
            'sandbox' => $sandbox,
            'dev_revision' => $revision ?: $now->format('YmdHis.u'),
            'hmr' => true,
            'source_maps' => true,
            'status' => 'available',
            'process_id' => $processId,
            'updated_at' => $now->format(DATE_ATOM),
            'expires_at' => $now->modify(sprintf('+%d seconds', $this->leaseSeconds))->format(DATE_ATOM),
        ];
        if (!is_dir($this->path) && !mkdir($this->path, 0700, true) && !is_dir($this->path)) {
            throw new RuntimeException('无法创建插件前端开发注册目录。');
        }
        $temporary = tempnam($this->path, '.frontend-dev-');
        if (!is_string($temporary)) {
            throw new RuntimeException('无法创建插件前端开发注册临时文件。');
        }
        file_put_contents($temporary, json_encode($registration, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($temporary, 0600);
        if (!rename($temporary, $this->registrationPath($code))) {
            @unlink($temporary);
            throw new RuntimeException('无法写入插件前端开发注册文件。');
        }

        return $registration;
    }

    public function unregister(string $code): bool
    {
        $this->assertCode($code);
        $path = $this->registrationPath($code);
        return !is_file($path) || unlink($path);
    }

    public function heartbeatSeconds(): int
    {
        return max(1, intdiv($this->leaseSeconds, 3));
    }

    private function enabled(): bool
    {
        return in_array($this->environment, ['local', 'testing'], true);
    }

    private function registrationPath(string $code): string
    {
        return $this->path.'/'.$code.'.json';
    }

    private function assertCode(string $code): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,63}$/', $code) !== 1) {
            throw new RuntimeException('插件编码格式无效。');
        }
    }

    private function assertUrl(string $entryUrl, string $allowedOrigin): void
    {
        $parts = parse_url($entryUrl);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true) || !isset($parts['host'])) {
            throw new RuntimeException('插件前端开发入口必须是有效的 HTTP(S) URL。');
        }
        $origin = $this->origin($entryUrl);
        if ($origin !== $allowedOrigin) {
            throw new RuntimeException('插件前端开发入口与允许 Origin 不一致。');
        }
        $host = strtolower((string) $parts['host']);
        $loopback = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        if (!$loopback && !in_array($origin, $this->allowedOrigins, true)) {
            throw new RuntimeException(sprintf('插件前端开发 Origin 未被宿主允许：%s', $origin));
        }
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        return strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host']).$port;
    }
}
