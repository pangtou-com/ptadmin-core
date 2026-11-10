<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Http\Controllers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PTAdmin\Admin\Auth\AuthorizationService;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Core\Http\Responses\AdminResponse;

/** 授权工作台的读取投影；导航与权限在存储和写入时始终独立。 */
final class AuthorizationCatalogController
{
    public function __construct(private readonly ConnectionInterface $database, private readonly AuthorizationService $authorization)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $session = $request->attributes->get('ptadmin.session', []);
        $admin = ($session['guard_name'] ?? '') === 'admin' ? Admin::query()->find($session['subject_id'] ?? null) : null;
        if (!$admin instanceof Admin) {
            return AdminResponse::error(40102, '管理员账号不可用。', null, 401);
        }
        if (!$this->authorization->allows($admin, 'sync_permissions', 'admin.admin_users')
            && !$this->authorization->allows($admin, 'sync_permissions', 'admin.admin_roles')
            && !$this->authorization->allows($admin, 'create', 'admin.admin_resources')
            && !$this->authorization->allows($admin, 'update', 'admin.admin_resources')) {
            return AdminResponse::error(40300, '当前账号无权配置权限。', null, 403);
        }
        $results = [];
        $collections = [
            'admin_resources' => $this->database->table('admin_resources')->whereNull('deleted_at')->orderBy('sort')->orderBy('id')->get(),
            'admin_menus' => $this->database->table('admin_menus')->whereNull('deleted_at')->orderBy('sort')->orderBy('id')->get(),
        ];
        foreach ($collections as $table => $rows) {
            foreach ($rows as $row) {
                $meta = json_decode((string) $row->meta_json, true, 512, JSON_THROW_ON_ERROR);
                $isNavigation = $table === 'admin_menus';
                $results[] = [
                    'id' => (int) $row->id, 'resource_key' => $isNavigation ? '__navigation__:'.$row->resource_key : $row->resource_key,
                    'title' => $row->title, 'type' => $isNavigation ? $row->type : 'permission',
                    'parent_key' => $isNavigation && $row->parent_key !== '' ? '__navigation__:'.$row->parent_key : '',
                    'path' => $isNavigation ? $row->path : '', 'icon' => $isNavigation ? $row->icon : null,
                    'is_menu' => $isNavigation && (bool) $row->is_menu, 'status' => (bool) $row->status,
                    'module' => $row->module, 'owner_type' => $meta['owner_type'] ?? '', 'owner_code' => $meta['owner_code'] ?? '',
                    'target_resource_key' => $isNavigation ? '' : ($meta['target_resource_key'] ?? ''),
                    'abilities' => $isNavigation ? [] : ($meta['abilities'] ?? []),
                    'operation_labels' => $isNavigation ? new \stdClass() : ($meta['operations'] ?? new \stdClass()),
                    'scopes' => $isNavigation ? [] : ($meta['scopes'] ?? ['all']),
                    'permission_keys' => $isNavigation ? ($meta['permission_keys'] ?? []) : [],
                    'entry_permission' => $isNavigation ? $row->entry_permission : '',
                ];
            }
        }
        return AdminResponse::success('', ['resources' => $results]);
    }
}
