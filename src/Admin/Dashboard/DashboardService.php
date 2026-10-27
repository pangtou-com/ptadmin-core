<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Dashboard;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Admin\Auth\AuthorizationService;
use PTAdmin\Notice\NoticeInboxService;
use PTAdmin\Notice\NoticeReceiver;

final class DashboardService
{
    public function __construct(
        private readonly ConnectionInterface $database,
        private readonly NoticeInboxService $noticeInbox,
        private readonly AuthorizationService $authorization,
    ) {
    }

    /** @return array<string, mixed> */
    public function dashboard(Admin $admin): array
    {
        return [
            'key' => 'dashboard',
            'title' => (string) config('ptadmin-dashboard.title', '工作概览'),
            'description' => (string) config('ptadmin-dashboard.description', ''),
            'editable' => (bool) config('ptadmin-dashboard.editable', true),
            'overview_widget' => (string) config('ptadmin-dashboard.overview_widget', 'ptadmin.workspace-summary'),
            'welcome' => $this->welcome($admin),
            'summary' => $this->summary(),
            'widgets' => $this->selectedWidgets($admin),
        ];
    }

    /** @return array{available_widgets:list<array<string,mixed>>,selected_widgets:list<array<string,mixed>>} */
    public function settings(Admin $admin): array
    {
        return [
            'available_widgets' => $this->availableWidgets($admin),
            'selected_widgets' => $this->selectedWidgets($admin),
        ];
    }

    /** @param list<mixed> $widgets @return array{saved:int} */
    public function saveSettings(Admin $admin, array $widgets): array
    {
        $definitions = array_column($this->availableWidgets($admin), null, 'code');
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

        if (!(bool) config('ptadmin-dashboard.editable', true)) {
            throw new \InvalidArgumentException('当前应用不允许调整仪表盘布局。');
        }
        $now = now()->timestamp;
        $rows = array_map(static fn (array $widget): array => [
            'subject_type' => 'admin', 'subject_id' => (int) $admin->getKey(),
            ...$widget, 'created_at' => $now, 'updated_at' => $now,
        ], $normalized);
        if ($rows !== []) {
            $this->database->table('admin_dashboard_widgets')->upsert(
                $rows,
                ['subject_type', 'subject_id', 'widget_code'],
                ['enabled', 'sort', 'layout_json', 'config_json', 'updated_at'],
            );
        }

        return ['saved' => count($normalized)];
    }

    /** @param list<mixed> $requests @return array{results:list<array<string,mixed>>} */
    public function query(Admin $admin, array $requests): array
    {
        $definitions = array_column($this->availableWidgets($admin), null, 'code');
        $codes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $request): string => is_array($request) ? (string) ($request['code'] ?? '') : '',
            $requests,
        ), static fn (string $code): bool => isset($definitions[$code]))));
        $datasets = [];
        $errors = [];
        $providerClass = config('ptadmin-dashboard.provider');
        if (is_string($providerClass) && $providerClass !== '' && $codes !== []) {
            $provider = app($providerClass);
            if (!$provider instanceof DashboardDataProviderInterface) {
                throw new \LogicException('仪表盘数据提供器必须实现 DashboardDataProviderInterface：'.$providerClass);
            }
            try {
                $datasets = $provider->query($admin, $codes);
            } catch (\Throwable $exception) {
                report($exception);
                $errors = array_fill_keys($codes, ['message' => '仪表盘业务数据读取失败，请稍后重试。']);
            }
        }
        $needs = static fn (string $code): bool => in_array($code, $codes, true)
            && !array_key_exists($code, $datasets) && !isset($errors[$code]);
        $menus = [];
        if ($needs('ptadmin.workspace-summary') || $needs('ptadmin.quick-actions')) {
            $menus = $this->functionMenus($admin);
        }
        // 固定组件在映射结果前查询；循环只组装响应，不逐项访问数据库。
        if ($needs('ptadmin.workspace-summary')) {
            $this->readWidget($datasets, $errors, 'ptadmin.workspace-summary', fn (): array => $this->workspaceSummary($admin, $menus));
        }
        if ($needs('ptadmin.quick-actions')) {
            $datasets['ptadmin.quick-actions'] = $this->quickActions($menus);
        }
        if ($needs('ptadmin.recent-operations')) {
            $this->readWidget($datasets, $errors, 'ptadmin.recent-operations', fn (): array => $this->recentOperations($admin));
        }
        if ($needs('ptadmin.operation-trend')) {
            $this->readWidget($datasets, $errors, 'ptadmin.operation-trend', fn (): array => $this->operationTrend($admin));
        }
        if ($needs('ptadmin.version-notices')) {
            $datasets['ptadmin.version-notices'] = $this->versionNotices();
        }
        if ($needs('ptadmin.notifications')) {
            $this->readWidget($datasets, $errors, 'ptadmin.notifications', fn (): array => $this->notifications($admin));
        }
        $results = [];
        foreach ($requests as $request) {
            $code = is_array($request) ? (string) ($request['code'] ?? '') : '';
            $definition = $definitions[$code] ?? null;
            $data = $definition !== null ? ($datasets[$code] ?? null) : null;
            $results[] = [
                'code' => $code,
                'widget' => $definition,
                'data' => $data,
                'error' => $definition === null ? ['message' => '仪表盘组件不存在或无权访问。']
                    : ($errors[$code] ?? ($data === null ? ['message' => '仪表盘组件尚未提供数据。'] : null)),
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
        $definitions = array_column($this->availableWidgets($admin), null, 'code');
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
    private function availableWidgets(Admin $admin): array
    {
        $configured = (array) config('ptadmin-dashboard.widgets', []);
        $requiresPermissions = array_filter($configured, static fn (array $widget): bool => isset($widget['permission']));
        $permissions = $requiresPermissions !== [] ? $this->authorization->permissions($admin) : [];
        $widgets = [];
        foreach ($configured as $code => $widget) {
            if (($widget['enabled'] ?? true) === false) {
                continue;
            }
            if (isset($widget['permission']) && !in_array('*', $permissions, true)
                && !in_array($widget['permission'], $permissions, true)) {
                continue;
            }
            $widgets[] = [
                ...$widget,
                'code' => $code,
                'default_enabled' => (bool) ($widget['default_enabled'] ?? true),
                'enabled' => (bool) ($widget['default_enabled'] ?? true),
                'default_layout' => array_replace([
                    'x' => 0, 'y' => 0, 'w' => 6, 'h' => 4, 'min_w' => 4, 'min_h' => 3, 'max_w' => 12, 'max_h' => 8,
                ], $widget['default_layout'] ?? []),
            ];
        }

        return $widgets;
    }

    /**
     * @param array<string,array<string,mixed>> $datasets
     * @param array<string,array{message:string}> $errors
     * @param callable(): array<string,mixed> $read
     */
    private function readWidget(array &$datasets, array &$errors, string $code, callable $read): void
    {
        try {
            $datasets[$code] = $read();
        } catch (\Throwable $exception) {
            report($exception);
            $errors[$code] = ['message' => '组件数据读取失败，请稍后重试。'];
        }
    }

    /** @return array<string,mixed> */
    private function welcome(Admin $admin): array
    {
        $logins = $this->database->table('admin_login_logs')->where('admin_id', $admin->getKey())->where('successful', true);
        $count = (clone $logins)->count();
        $previous = $logins->orderByDesc('id')->skip(1)->first(['created_at', 'ip_address']);

        return [
            'name' => $admin->getAttribute('nickname') ?: $admin->getAttribute('username'),
            'identity' => (bool) $admin->getAttribute('is_founder') ? '创始人' : '管理员',
            'login_count' => $count,
            'previous_login_at' => $previous !== null ? Carbon::parse((string) $previous->created_at)->toIso8601String() : null,
            'previous_login_ip' => $previous?->ip_address ?: null,
            'can_manage_system' => (bool) $admin->getAttribute('is_founder'),
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
