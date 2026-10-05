<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Frontend;

use RuntimeException;

/**
 * 校验 frontend.json 中声明的本地开发入口；生产环境始终忽略。
 *
 * 开发服务器由应用或插件开发者自行启动，不维护宿主侧注册文件或进程租约。
 */
final class FrontendDevelopmentStore
{
    /** @param array<int, string> $allowedOrigins */
    public function __construct(
        private string $environment,
        private array $allowedOrigins = [],
    ) {
    }

    /**
     * 读取应用或插件 frontend.json 内联的开发入口。
     *
     * 内联入口只在 local/testing 环境生效，不参与正式发布包的入口替换。
     * @param mixed $declaration
     * @return array<string, mixed>|null
     */
    public function resolveInline(string $code, mixed $declaration): ?array
    {
        if (!$this->enabled() || null === $declaration) {
            return null;
        }
        $this->assertCode($code);
        if (!is_array($declaration) || array_is_list($declaration)) {
            throw new RuntimeException(sprintf('前端模块 %s 的前端开发声明无效。', $code));
        }

        $entryUrl = (string) ($declaration['entry_url'] ?? '');
        $sandbox = (string) ($declaration['sandbox'] ?? '');
        if (!in_array($sandbox, ['trusted', 'iframe'], true)) {
            throw new RuntimeException(sprintf('前端模块 %s 的前端开发 sandbox 无效。', $code));
        }
        $origin = $this->origin($entryUrl);
        $allowedOrigin = (string) ($declaration['allowed_origin'] ?? $origin);
        $this->assertUrl($entryUrl, $allowedOrigin);

        return [
            'code' => $code,
            'entry_url' => $entryUrl,
            'allowed_origin' => $allowedOrigin,
            'sandbox' => $sandbox,
            'dev_revision' => (string) ($declaration['dev_revision'] ?? 'inline'),
            'hmr' => (bool) ($declaration['hmr'] ?? true),
            'source_maps' => (bool) ($declaration['source_maps'] ?? true),
            'status' => 'available',
        ];
    }

    private function enabled(): bool
    {
        return in_array($this->environment, ['local', 'testing'], true);
    }

    private function assertCode(string $code): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,63}$/', $code) !== 1) {
            throw new RuntimeException('前端模块编码格式无效。');
        }
    }

    private function assertUrl(string $entryUrl, string $allowedOrigin): void
    {
        $parts = parse_url($entryUrl);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true) || !isset($parts['host'])) {
            throw new RuntimeException('前端模块开发入口必须是有效的 HTTP(S) URL。');
        }
        $origin = $this->origin($entryUrl);
        if ($origin !== $allowedOrigin) {
            throw new RuntimeException('前端模块开发入口与允许 Origin 不一致。');
        }
        $host = strtolower((string) $parts['host']);
        $loopback = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        if (!$loopback && !in_array($origin, $this->allowedOrigins, true)) {
            throw new RuntimeException(sprintf('前端模块开发 Origin 未被宿主允许：%s', $origin));
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
