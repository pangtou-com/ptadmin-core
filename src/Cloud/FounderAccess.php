<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

use Illuminate\Http\Request;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Admin\Auth\AuthorizationService;

final class FounderAccess
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
        if (!(bool) $admin->getAttribute('is_founder') || !(bool) $admin->getAttribute('status') || !$this->authorization->identity($admin)->superuser) {
            throw new CloudException('只有创始人可以使用云市场。', 'founder_required');
        }
        return $admin;
    }
}
