<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Manifest;

use PTAdmin\Addon\Exception\AddonException;

/** 统一 manifest 的 PHP 代码身份；缺省前缀沿用插件 code 的现有推导规则。 */
final class AddonNamespace
{
    /** @param array<string, mixed> $manifest */
    public static function fromManifest(array $manifest): string
    {
        $code = (string) ($manifest['code'] ?? '');
        if (array_key_exists('namespace', $manifest)) {
            if (!is_string($manifest['namespace'])) {
                throw new AddonException('插件 namespace 必须为文本：'.$code, 'invalid_addon_namespace');
            }
            $namespace = trim($manifest['namespace'], '\\');
        } else {
            $parts = preg_split('/[^A-Za-z0-9]+/', $code, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $namespace = 'PTAdmin\\Addons\\'.implode('', array_map(
                static fn (string $part): string => ucfirst(strtolower($part)), $parts,
            ));
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $namespace) !== 1) {
            throw new AddonException('插件 namespace 格式无效：'.$code, 'invalid_addon_namespace');
        }
        $prefix = strtolower($namespace).'\\';
        if ((str_starts_with($prefix, 'ptadmin\\') && !str_starts_with($prefix, 'ptadmin\\addons\\'))
            || $prefix === 'ptadmin\\addons\\'
            || str_starts_with($prefix, 'app\\')
            || str_starts_with($prefix, 'composer\\')
            || str_starts_with($prefix, 'illuminate\\')) {
            throw new AddonException('插件 namespace 占用了保留的代码空间：'.$code, 'addon_namespace_reserved');
        }

        return $namespace;
    }
}
