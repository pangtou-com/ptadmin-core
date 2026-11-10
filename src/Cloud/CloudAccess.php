<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

use Illuminate\Http\Request;
use PTAdmin\Admin\Models\Admin;

final class CloudAccess
{
    public function assert(Request $request): Admin
    {
        $session = $request->attributes->get('ptadmin.session');
        $admin = is_array($session) ? Admin::query()->find($session['subject_id'] ?? null) : null;
        if (!$admin instanceof Admin) {
            throw new CloudException('管理员会话无效。', 'unauthorized');
        }
        $this->assertFounder($admin);
        return $admin;
    }

    public function assertFounder(Admin $admin): void
    {
        if (!\PTAdmin\Admin\Auth\FounderAccess::allows($admin)) {
            throw new CloudException('云市场仅允许启用的创始人账号操作。', 'forbidden');
        }
    }
}
