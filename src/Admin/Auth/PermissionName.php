<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth;

use InvalidArgumentException;

/** 统一入口只接受具体的资源操作，不接受通配符授权请求。 */
final class PermissionName
{
    /** @return array{string,string} 资源编码、操作编码 */
    public static function parse(string $permission): array
    {
        $position = strrpos($permission, '.');
        $resource = $position === false ? '' : substr($permission, 0, $position);
        $ability = $position === false ? '' : substr($permission, $position + 1);
        if (!preg_match('/^[a-z][a-z0-9._-]{1,190}$/D', $resource)
            || str_contains($resource, '..') || str_ends_with($resource, '.')
            || !preg_match('/^[a-z][a-z0-9_-]{0,99}$/D', $ability)
            || $ability === 'access') {
            throw new InvalidArgumentException('后台权限编码必须是有效的资源编码.操作编码：'.$permission);
        }

        return [$resource, $ability];
    }
}
