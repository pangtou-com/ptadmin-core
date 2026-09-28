<?php

declare(strict_types=1);

namespace PTAdmin\Core\Config;

/** 从 site.url 生成站点公开绝对地址，保留配置中的部署子目录。 */
final class SiteUrl
{
    public function __construct(private ConfigService $configs)
    {
    }

    public function to(string $path = ''): string
    {
        $base = $this->configs->value('site.url');
        if (!is_string($base) || filter_var($base, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($base, PHP_URL_SCHEME)), ['http', 'https'], true)
            || parse_url($base, PHP_URL_QUERY) !== null || parse_url($base, PHP_URL_FRAGMENT) !== null
            || parse_url($base, PHP_URL_USER) !== null || parse_url($base, PHP_URL_PASS) !== null) {
            throw new ConfigException('请在系统配置中设置有效的站点地址 site.url（不含查询参数、片段或账号的 HTTP(S) 地址）。', 'site_url_invalid');
        }

        return rtrim($base, '/').($path === '' ? '' : '/'.ltrim($path, '/'));
    }
}
