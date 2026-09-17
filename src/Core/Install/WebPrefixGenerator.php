<?php

declare(strict_types=1);

namespace PTAdmin\Core\Install;

use InvalidArgumentException;

final class WebPrefixGenerator
{
    /**
     * 后台入口允许多个路径片段，但每个片段都必须是稳定的 URL 标识。
     */
    public const PATTERN = '/^[a-z][a-z0-9_-]{2,63}(?:\/[a-z][a-z0-9_-]{2,63})*$/';

    /** @var list<string> 不能被后台入口占用的系统路径片段。 */
    private const RESERVED_SEGMENTS = [
        'api',
        'assets',
        'install',
        'modules',
        'ptadmin',
        'storage',
    ];

    public function generate(): string
    {
        return 'admin_'.substr(bin2hex(random_bytes(8)), 0, 12);
    }

    public function assertValid(string $prefix): string
    {
        $normalized = trim($prefix, '/');
        if ($normalized === '' || strlen($normalized) > 128 || preg_match(self::PATTERN, $normalized) !== 1) {
            throw new InvalidArgumentException('The PTAdmin web prefix must contain only lowercase URL path segments.');
        }

        $firstSegment = explode('/', $normalized, 2)[0];
        if (in_array($firstSegment, self::RESERVED_SEGMENTS, true)) {
            throw new InvalidArgumentException(sprintf('The PTAdmin web prefix is reserved: %s.', $firstSegment));
        }

        return $normalized;
    }

    public static function isValid(string $prefix): bool
    {
        try {
            (new self())->assertValid($prefix);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
