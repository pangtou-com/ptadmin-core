<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Dashboard;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Core\Install\AdminFrontendManifest;
use PTAdmin\Notice\NoticeInboxService;
use PTAdmin\Notice\NoticeReceiver;

final class DashboardService
{
    public function __construct(
        private ConnectionInterface $database,
        private AdminFrontendManifest $frontendManifest,
        private NoticeInboxService $noticeInbox,
    ) {
    }

    /** @return array<string, mixed> */
    public function dashboard(Admin $admin): array
    {
        return [
            'key' => 'dashboard',
            'title' => 'PTAdmin 工作台',
            'description' => '平台核心状态与工作概览',
            'summary' => $this->summary(),
            'widgets' => $this->selectedWidgets($admin),
        ];
    }

    /** @return array{available_widgets:list<array<string,mixed>>,selected_widgets:list<array<string,mixed>>} */
    public function settings(Admin $admin): array
    {
        return [
            'available_widgets' => $this->availableWidgets(),
            'selected_widgets' => $this->selectedWidgets($admin),
        ];
    }

    /** @param list<array<string,mixed>> $widgets @return array{saved:int} */
    public function saveSettings(Admin $admin, array $widgets): array
    {
        $definitions = array_column($this->availableWidgets(), null, 'code');
        $seen = [];
        $normalized = [];
        foreach ($widgets as $index => $widget) {
            if (!is_array($widget) || array_is_list($widget)) {
                throw new \InvalidArgumentException(sprintf('第 %d 个仪表盘组件配置格式不正确。', $index + 1));
            }
            $code = (string) ($widget['widget_code'] ?? '');
            if (!isset($definitions[$code])) {
                throw new \InvalidArgumentException('仪表盘组件不存在：'.$code);
            }
            if (isset($seen[$code])) {
                throw new \InvalidArgumentException('仪表盘组件配置重复：'.$code);
            }
            $seen[$code] = true;
            $normalized[] = [
                'widget_code' => $code,
                'enabled' => (bool) ($widget['enabled'] ?? false),
                'sort' => max(0, (int) ($widget['sort'] ?? $index)),
                'layout_json' => $this->encodeObject($widget['layout'] ?? []),
                'config_json' => $this->encodeObject($widget['config'] ?? []),
            ];
        }

        $now = now()->timestamp;
        $this->database->transaction(function () use ($admin, $normalized, $now): void {
            foreach ($normalized as $widget) {
                $identity = [
                    'subject_type' => 'admin',
                    'subject_id' => (int) $admin->getKey(),
                    'widget_code' => $widget['widget_code'],
                ];
                $existing = $this->database->table('admin_dashboard_widgets')
                    ->where('subject_type', 'admin')
                    ->where('subject_id', (int) $admin->getKey())
                    ->where('widget_code', $widget['widget_code'])
                    ->first();
                if (null === $existing) {
                    $this->database->table('admin_dashboard_widgets')->insert($identity + $widget + [
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    $this->database->table('admin_dashboard_widgets')->where('id', $existing->id)->update($widget + [
                        'updated_at' => $now,
                    ]);
                }
            }
        });

        return ['saved' => count($normalized)];
    }

    /** @param list<array<string,mixed>> $requests @return array{results:list<array<string,mixed>>} */
    public function query(Admin $admin, array $requests): array
    {
        $definitions = array_column($this->availableWidgets(), null, 'code');
        $results = [];
        foreach ($requests as $request) {
            $code = is_array($request) ? (string) ($request['code'] ?? '') : '';
            if (!isset($definitions[$code])) {
                $results[] = [
                    'code' => $code,
                    'widget' => null,
                    'data' => null,
                    'error' => ['message' => '仪表盘组件不存在。'],
                    'queried_at' => (int) floor(microtime(true) * 1000),
                ];
                continue;
            }
            $results[] = [
                'code' => $code,
                'widget' => $definitions[$code],
                'data' => $this->widgetData($code, $admin),
                'error' => null,
                'queried_at' => (int) floor(microtime(true) * 1000),
            ];
        }

        return ['results' => $results];
    }

    /** @return array<string,mixed> */
    public function summary(bool $synchronized = false): array
    {
        $frontendVersion = $this->frontendVersion();
        $backendVersion = (string) config('ptadmin.backend_version', '0.1.0');

        return [
            'frontend_version' => $frontendVersion,
            'frontend_latest_version' => $frontendVersion,
            'frontend_update_required' => false,
            'backend_version' => $backendVersion,
            'backend_latest_version' => $backendVersion,
            'backend_update_required' => false,
            'application_sync_status' => $synchronized ? 'success' : 'idle',
            'application_sync_last_succeeded_at' => $synchronized ? now()->toIso8601String() : null,
            'application_advice' => [],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function selectedWidgets(Admin $admin): array
    {
        $definitions = array_column($this->availableWidgets(), null, 'code');
        $rows = $this->database->table('admin_dashboard_widgets')
            ->where('subject_type', 'admin')
            ->where('subject_id', (int) $admin->getKey())
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
        if ($rows->isEmpty()) {
            return array_values(array_filter(
                $definitions,
                static fn (array $widget): bool => (bool) ($widget['default_enabled'] ?? false),
            ));
        }

        $selected = [];
        foreach ($rows as $row) {
            $code = (string) $row->widget_code;
            if (!isset($definitions[$code])) {
                continue;
            }
            $selected[] = array_replace($definitions[$code], [
                'enabled' => (bool) $row->enabled,
                'sort' => (int) $row->sort,
                'layout' => $this->decodeObject($row->layout_json),
                'config' => $this->decodeObject($row->config_json),
            ]);
        }

        return $selected;
    }

    /** @return list<array<string,mixed>> */
    private function availableWidgets(): array
    {
        return [
            $this->widget('ptadmin.workspace-summary', '我的工作概览', 'stats', 0, 0, true),
            $this->widget('ptadmin.quick-actions', '快捷入口', 'card', 6, 0, true),
            $this->widget('ptadmin.recent-operations', '最近操作', 'list', 0, 4, true),
            $this->widget('ptadmin.operation-trend', '操作趋势', 'trend', 6, 4, true),
            $this->widget('ptadmin.version-notices', '版本与更新', 'list', 0, 8, false),
            $this->widget('ptadmin.notifications', '我的通知/待办', 'list', 6, 8, false),
        ];
    }

    /** @return array<string,mixed> */
    private function widget(string $code, string $title, string $type, int $x, int $y, bool $enabled): array
    {
        return [
            'code' => $code,
            'title' => $title,
            'type' => $type,
            'group' => 'core',
            'addon_code' => 'ptadmin',
            'default_enabled' => $enabled,
            'enabled' => $enabled,
            'default_layout' => [
                'x' => $x,
                'y' => $y,
                'w' => 6,
                'h' => 4,
                'min_w' => 4,
                'min_h' => 4,
                'max_w' => 12,
                'max_h' => 8,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function widgetData(string $code, Admin $admin): array
    {
        return match ($code) {
            'ptadmin.workspace-summary' => $this->workspaceSummary($admin),
            'ptadmin.quick-actions' => $this->quickActions(),
            'ptadmin.recent-operations' => $this->recentOperations($admin),
            'ptadmin.operation-trend' => $this->operationTrend($admin),
            'ptadmin.version-notices' => $this->versionNotices(),
            'ptadmin.notifications' => ['type' => 'list', 'items' => []],
            default => ['type' => 'list', 'items' => []],
        };
    }

    /** @return array<string,mixed> */
    private function workspaceSummary(Admin $admin): array
    {
        $roleCount = $this->database->table('admin_user_roles')->where('admin_id', $admin->getKey())->count();
        $resourceCount = $this->database->table('admin_resources')->where('status', true)->whereNull('deleted_at')->count();
        $unreadCount = $this->noticeInbox->unreadCount(NoticeReceiver::admin((int) $admin->getKey()));

        return ['type' => 'stats', 'items' => [
            ['id' => 'roles', 'label' => '我的角色', 'value' => $roleCount, 'unit' => '个', 'tone' => 'success'],
            ['id' => 'resources', 'label' => '可访问资源', 'value' => $resourceCount, 'unit' => '项', 'tone' => 'success'],
            ['id' => 'unread_notifications', 'label' => '未读通知', 'value' => $unreadCount, 'unit' => '条', 'tone' => $unreadCount > 0 ? 'warning' : 'default'],
        ]];
    }

    /** @return array<string,mixed> */
    private function quickActions(): array
    {
        return ['type' => 'card', 'items' => [
            ['id' => 'shortcut-users', 'label' => '管理员管理', 'description' => '账号和权限', 'target' => '/admin/users'],
            ['id' => 'shortcut-schema', 'label' => 'Schema 资源', 'description' => '字段和 CRUD', 'target' => '/schema/resources'],
            ['id' => 'shortcut-cloud', 'label' => '云市场', 'description' => '扩展与安装', 'target' => '/cloud/market'],
            ['id' => 'shortcut-tasks', 'label' => '任务中心', 'description' => '发布任务', 'target' => '/tasks'],
        ]];
    }

    /** @return array<string,mixed> */
    private function recentOperations(Admin $admin): array
    {
        $items = $this->database->table('admin_operation_records')
            ->where('admin_id', $admin->getKey())
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(static fn (object $row): array => [
                'id' => 'operation-'.$row->id,
                'title' => (string) $row->action,
                'meta' => Carbon::parse((string) $row->created_at)->format('m-d H:i').' · '.$row->status_code,
                'status' => (bool) $row->successful ? 'success' : 'danger',
            ])->all();

        return ['type' => 'list', 'items' => $items];
    }

    /** @return array<string,mixed> */
    private function operationTrend(Admin $admin): array
    {
        $days = [];
        for ($offset = 6; $offset >= 0; --$offset) {
            $date = now()->subDays($offset);
            $days[$date->format('Y-m-d')] = ['label' => $date->isoFormat('ddd'), 'value' => 0];
        }
        $rows = $this->database->table('admin_operation_records')
            ->where('admin_id', $admin->getKey())
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->get(['created_at']);
        foreach ($rows as $row) {
            $key = Carbon::parse((string) $row->created_at)->format('Y-m-d');
            if (isset($days[$key])) {
                ++$days[$key]['value'];
            }
        }

        return [
            'type' => 'trend',
            'categories' => array_column($days, 'label'),
            'series' => array_column($days, 'value'),
            'chart' => 'line',
        ];
    }

    /** @return array<string,mixed> */
    private function versionNotices(): array
    {
        $summary = $this->summary();

        return ['type' => 'list', 'items' => [
            ['id' => 'frontend', 'title' => '后台前端 '.$summary['frontend_version'], 'meta' => '当前版本', 'status' => 'success'],
            ['id' => 'backend', 'title' => 'PTAdmin Core '.$summary['backend_version'], 'meta' => '当前版本', 'status' => 'success'],
        ]];
    }

    private function frontendVersion(): string
    {
        $webPrefix = (string) config('ptadmin.web_prefix', 'admin');
        $published = public_path($webPrefix);
        $directory = is_dir($published) ? $published : (string) config('ptadmin.frontend.bundled_path');

        return (string) $this->frontendManifest->verify($directory)['frontend_version'];
    }

    /** @return array<string,mixed> */
    private function decodeObject(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }

    private function encodeObject(mixed $value): string
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \InvalidArgumentException('仪表盘布局和配置必须是对象。');
        }

        return json_encode($value === [] ? (object) [] : $value, JSON_THROW_ON_ERROR);
    }
}
