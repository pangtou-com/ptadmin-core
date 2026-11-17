<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PTAdmin\Admin\Facades\AdminAuth;
use PTAdmin\Admin\Dashboard\DashboardService;
use PTAdmin\Admin\Dashboard\DashboardWidgetUnavailableException;
use PTAdmin\Core\Http\Responses\AdminResponse;

final class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboard)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return AdminResponse::success('', $this->dashboard->dashboard(AdminAuth::authenticate()));
    }

    public function settings(Request $request): JsonResponse
    {
        return AdminResponse::success('', $this->dashboard->settings(AdminAuth::authenticate()));
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $widgets = $request->input('widgets');
        if (!is_array($widgets) || !array_is_list($widgets) || count($widgets) > 200) {
            return AdminResponse::error(42200, '仪表盘组件配置必须是最多 200 项的数组。', null, 422);
        }

        try {
            return AdminResponse::success('', $this->dashboard->saveSettings(AdminAuth::authenticate(), $widgets));
        } catch (DashboardWidgetUnavailableException $exception) {
            return AdminResponse::error(42200, $exception->getMessage(), ['reason' => 'widget_unavailable'], 422);
        } catch (\InvalidArgumentException $exception) {
            return AdminResponse::error(42200, $exception->getMessage(), null, 422);
        }
    }

    public function query(Request $request): JsonResponse
    {
        $widgets = $request->input('widgets');
        if (!is_array($widgets) || !array_is_list($widgets) || count($widgets) > 100) {
            return AdminResponse::error(42200, '仪表盘组件查询必须是最多 100 项的数组。', null, 422);
        }

        return AdminResponse::success('', $this->dashboard->query(AdminAuth::authenticate(), $widgets));
    }

    public function synchronize(Request $request): JsonResponse
    {
        if (!app(\PTAdmin\Admin\Auth\AuthorizationService::class)->allows(AdminAuth::authenticate(), 'manage', 'system.maintenance')) {
            return AdminResponse::error(40300, '当前账号没有系统维护权限。', null, 403);
        }

        return AdminResponse::success('', $this->dashboard->summary(true));
    }
}
