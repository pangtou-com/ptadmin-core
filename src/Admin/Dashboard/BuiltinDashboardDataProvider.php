<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Dashboard;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Admin\Auth\AuthorizationService;
use PTAdmin\Notice\NoticeInboxService;
use PTAdmin\Notice\NoticeReceiver;

final class BuiltinDashboardDataProvider implements DashboardDataProviderInterface
{
    public function __construct(
        private readonly ConnectionInterface $database,
        private readonly NoticeInboxService $noticeInbox,
        private readonly AuthorizationService $authorization,
    ) {
    }

    /** @var array<string,array{reason:string,message:string}> */
    private array $errors = [];

    public function errors(): array
    {
        return $this->errors;
    }

    public function query(Admin $admin, array $codes): array
    {
        $this->errors = [];
        $datasets = [];
        $menus = [];
        if (in_array('ptadmin.workspace-summary', $codes, true) || in_array('ptadmin.quick-actions', $codes, true)) {
            try {
                $menus = $this->functionMenus($admin);
            } catch (\Throwable $exception) {
                report($exception);
                $this->errors = array_fill_keys(array_intersect($codes, ['ptadmin.workspace-summary', 'ptadmin.quick-actions']), [
                    'reason' => 'provider_failed', 'message' => '组件菜单读取失败，请稍后重试。',
                ]);
            }
        }
        // 每类内置数据最多执行一次批量读取，异常留在对应区块。
        $read = function (string $code, \Closure $query) use (&$datasets, $codes): void {
            if (!in_array($code, $codes, true) || isset($this->errors[$code])) {
                return;
            }
            try {
                $datasets[$code] = $query();
            } catch (\Throwable $exception) {
                report($exception);
                $this->errors[$code] = ['reason' => 'provider_failed', 'message' => '组件数据读取失败，请稍后重试。'];
            }
        };
        $read('ptadmin.workspace-summary', fn (): array => $this->workspaceSummary($admin, $menus));
        $read('ptadmin.quick-actions', fn (): array => $this->quickActions($menus));
        $read('ptadmin.recent-operations', fn (): array => $this->recentOperations($admin));
        $read('ptadmin.operation-trend', fn (): array => $this->operationTrend($admin));
        $read('ptadmin.version-notices', fn (): array => $this->versionNotices());
        $read('ptadmin.notifications', fn (): array => $this->notifications($admin));

        return $datasets;
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
    private function functionMenus(Admin $admin): array
    {
        $pending = $this->authorization->menus($admin);
        $functions = [];
        while ($pending !== []) {
            $menu = array_shift($pending);
            if (($menu['children'] ?? []) !== []) {
                array_push($pending, ...$menu['children']);
            }
            if (in_array($menu['type'], ['page', 'schema'], true) && $menu['key'] !== 'dashboard' && $menu['path'] !== '') {
                $functions[] = $menu;
            }
        }

        return $functions;
    }

    /** @param list<array<string,mixed>> $menus @return array<string,mixed> */
    private function workspaceSummary(Admin $admin, array $menus): array
    {
        $operations = $this->database->table('admin_operation_records')->where('admin_id', $admin->getKey())
            ->where('created_at', '>=', now()->subDays(6)->startOfDay());
        $weekCount = (clone $operations)->count();
        $todayCount = $operations->where('created_at', '>=', now()->startOfDay())->count();
        $unreadCount = $this->noticeInbox->unreadCount(NoticeReceiver::admin((int) $admin->getKey()));

        return ['type' => 'stats', 'items' => [
            ['id' => 'today_operations', 'label' => '今日操作', 'value' => $todayCount, 'unit' => '次', 'description' => '今日个人操作记录', 'icon' => 'activity'],
            ['id' => 'week_operations', 'label' => '近七天操作', 'value' => $weekCount, 'unit' => '次', 'description' => '含今日的七天累计', 'icon' => 'chart'],
            ['id' => 'unread_notifications', 'label' => '未读通知', 'value' => $unreadCount, 'unit' => '条', 'description' => '尚未阅读的站内通知', 'icon' => 'bell', 'tone' => $unreadCount > 0 ? 'warning' : 'default'],
            ['id' => 'available_functions', 'label' => '可用功能', 'value' => count($menus), 'unit' => '项', 'description' => '当前账号获授权的功能', 'icon' => 'grid'],
        ]];
    }

    /** @param list<array<string,mixed>> $menus @return array<string,mixed> */
    private function quickActions(array $menus): array
    {
        $keys = (array) config('ptadmin-dashboard.shortcut_keys', []);
        if ($keys !== []) {
            $indexed = array_column($menus, null, 'key');
            $menus = array_values(array_filter(array_map(static fn (string $key): ?array => $indexed[$key] ?? null, $keys)));
        }

        return ['type' => 'card', 'items' => array_map(static fn (array $menu): array => [
            'id' => $menu['key'], 'label' => $menu['title'], 'description' => '打开'.$menu['title'],
            'target' => $menu['path'], 'icon' => $menu['icon'] ?? 'grid',
        ], array_slice($menus, 0, 8))];
    }

    /** @return array<string,mixed> */
    private function notifications(Admin $admin): array
    {
        $messages = $this->noticeInbox->paginate(NoticeReceiver::admin((int) $admin->getKey()), 'unread', 1, 6);

        return ['type' => 'list', 'items' => array_map(static fn (array $message): array => [
            'id' => (string) $message['id'], 'title' => $message['title'],
            'meta' => Carbon::createFromTimestamp($message['created_at'])->format('m-d H:i'),
            'status' => $message['level'] === 'error' ? 'danger' : ($message['level'] === 'warning' ? 'warning' : 'default'),
        ], $messages['results'])];
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
            ->selectRaw('DATE(created_at) as operation_day, COUNT(*) as operation_count')
            ->groupByRaw('DATE(created_at)')
            ->get();
        foreach ($rows as $row) {
            $key = (string) $row->operation_day;
            if (isset($days[$key])) {
                $days[$key]['value'] = (int) $row->operation_count;
            }
        }

        return [
            'type' => 'trend',
            'categories' => array_column($days, 'label'),
            'series' => array_column($days, 'value'),
            'chart' => 'line',
            'unit' => '次',
            'period_label' => '最近七天',
        ];
    }

    /** @return array<string,mixed> */
    private function versionNotices(): array
    {
        $summary = $this->summary();
        $items = [];
        if (is_string($summary['frontend_version'])) {
            $items[] = ['id' => 'frontend', 'title' => '后台前端 '.$summary['frontend_version'], 'meta' => '当前版本', 'status' => 'success'];
        }
        $items[] = ['id' => 'backend', 'title' => 'PTAdmin Core '.$summary['backend_version'], 'meta' => '当前版本', 'status' => 'success'];

        return ['type' => 'list', 'items' => $items];
    }

    private function frontendVersion(): ?string
    {
        $webPrefix = (string) config('ptadmin.web_prefix', 'admin');
        $path = public_path($webPrefix.'/manifest.json');
        // 前端版本仅用于展示；清单缺失或不可读不应影响仪表盘接口。
        $contents = is_file($path) ? @file_get_contents($path) : false;
        if ($contents === false) {
            return null;
        }

        $manifest = json_decode($contents, true);
        $version = is_array($manifest) ? ($manifest['frontend_version'] ?? null) : null;

        return is_string($version) && trim($version) !== '' ? $version : null;
    }

}
