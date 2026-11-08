<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

use Illuminate\Http\Request;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Admin\Auth\AuthorizationService;

final class CloudAccess
{
    public function __construct(private readonly AuthorizationService $authorization)
    {
    }

    public function assert(Request $request): Admin
    {
        $session = $request->attributes->get('ptadmin.session');
        $admin = is_array($session) ? Admin::query()->find($session['subject_id'] ?? null) : null;
        if (!$admin instanceof Admin) {
            throw new CloudException('管理员会话无效。', 'unauthorized');
        }
        if (!(bool) $admin->getAttribute('status') || !$this->authorization->allows($admin, 'manage', 'cloud.market')) {
            throw new CloudException('当前账号没有云市场管理权限。', 'forbidden');
        }
        return $admin;
    }
}
