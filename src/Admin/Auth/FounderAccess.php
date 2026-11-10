<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth;

use Illuminate\Http\Request;
use PTAdmin\Admin\Models\Admin;

/** 固定系统功能的身份边界，不参与个人或角色授权计算。 */
final class FounderAccess
{
    public const RESOURCE_KEYS = [
        'cloud.market', 'addon.manage', 'addon.download', 'addon.install', 'addon.update',
        'addon.uninstall', 'addon.frontend', 'addon.enable', 'addon.disable',
    ];

    public function resolve(Request $request): ?Admin
    {
        $session = $request->attributes->get('ptadmin.session');
        if (!is_array($session) || ($session['guard_name'] ?? null) !== 'admin') {
            return null;
        }
        $admin = Admin::query()->find($session['subject_id'] ?? null);
        return $admin instanceof Admin && self::allows($admin) ? $admin : null;
    }

    public static function allows(Admin $admin): bool
    {
        return (bool) $admin->status && (bool) $admin->is_founder;
    }
}
