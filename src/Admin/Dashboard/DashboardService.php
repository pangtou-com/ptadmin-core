<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Dashboard;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Admin\Auth\AuthorizationService;

final class DashboardService
{
    public function __construct(
        private readonly ConnectionInterface $database,
        private readonly DashboardWidgetRegistry $registry,
        private readonly DashboardWidgetValidator $validator,
        private readonly BuiltinDashboardDataProvider $builtin,
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
            'overview_widget' => $this->overviewCode($admin),
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
        if (count($widgets) > 200) {
            throw new \InvalidArgumentException('仪表盘一次最多保存 200 个区块的变更。');
        }
        $definitions = array_column($this->availableWidgets($admin), null, 'code');
        $seen = [];
        $normalized = [];
        foreach ($widgets as $index => $widget) {
            if (!is_array($widget) || array_is_list($widget)) {
                throw new \InvalidArgumentException(sprintf('第 %d 个仪表盘组件配置格式不正确。', $index + 1));
            }
            $code = $widget['widget_code'] ?? null;
            if (!is_string($code) || (isset($widget['enabled']) && !is_bool($widget['enabled']))
                || (isset($widget['sort']) && (!is_int($widget['sort']) || $widget['sort'] < 0 || $widget['sort'] > 10000))) {
                throw new \InvalidArgumentException(sprintf('第 %d 个仪表盘组件编码、启停或排序格式不正确。', $index + 1));
            }
            if (!isset($definitions[$code])) {
                throw new DashboardWidgetUnavailableException('仪表盘组件不存在或权限已变化，请刷新区块目录：'.$code);
            }
            if (isset($seen[$code])) {
                throw new \InvalidArgumentException('仪表盘组件配置重复：'.$code);
            }
            $seen[$code] = true;
            $normalized[] = [
                'widget_code' => $code,
                'enabled' => $widget['enabled'] ?? false,
                'sort' => $widget['sort'] ?? $index,
                'layout_json' => $this->encodeObject($this->validator->layout($widget['layout'] ?? [], $code, $definitions[$code]['default_layout'])),
                'config_json' => $this->encodeObject($this->validator->config($widget['config'] ?? [], $code)),
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
        $entries = $this->registry->entries();
        $definitions = array_column($this->availableWidgets($admin, $entries), null, 'code');
        $codes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $request): string => is_array($request) && is_string($request['code'] ?? null) ? $request['code'] : '',
            $requests,
        ), static fn (string $code): bool => isset($definitions[$code]))));
        $datasets = [];
        $errors = [];
        $groups = [];
        foreach ($codes as $code) {
            $entry = $entries[$code];
            if ($entry['definition']['type'] === 'custom') {
                $datasets[$code] = ['type' => 'custom'];
                continue;
            }
            // 即便两个 owner 使用同一个类，也不能把不同归属的 codes 交给一次调用。
            $owner = isset($entry['definition']['addon_code']) ? 'addon:'.$entry['definition']['addon_code'] : 'host';
            $groups[$owner]['provider'] = $entry['provider'];
            $groups[$owner]['codes'][] = $code;
        }
        foreach ($groups as $group) {
            if ($group['provider'] === null || $group['provider'] === '') {
                continue;
            }
            try {
                $provider = app($group['provider']);
                if (!$provider instanceof DashboardDataProviderInterface) {
                    throw new \LogicException('仪表盘提供器必须实现 DashboardDataProviderInterface：'.$group['provider']);
                }
                $provided = $provider->query($admin, $group['codes']);
                $datasets += array_intersect_key($provided, array_fill_keys($group['codes'], true));
            } catch (\Throwable $exception) {
                report($exception);
                $errors += array_fill_keys($group['codes'], ['reason' => 'provider_failed', 'message' => '仪表盘业务数据读取失败，请稍后重试。']);
            }
        }
        $builtinCodes = array_values(array_filter($codes, static fn (string $code): bool =>
            !isset($definitions[$code]['addon_code']) && !array_key_exists($code, $datasets) && !isset($errors[$code])));
        $datasets += $this->builtin->query($admin, $builtinCodes);
        $errors += $this->builtin->errors();
        foreach ($datasets as $code => $dataset) {
            try {
                $datasets[$code] = $this->validator->dataset($dataset, $definitions[$code]['type'], $code);
            } catch (\InvalidArgumentException $exception) {
                report($exception);
                unset($datasets[$code]);
                $errors[$code] = ['reason' => 'invalid_data', 'message' => '仪表盘组件数据格式错误：'.$code];
            }
        }
        $results = [];
        foreach ($requests as $request) {
            $code = is_array($request) && is_string($request['code'] ?? null) ? $request['code'] : '';
            $definition = $definitions[$code] ?? null;
            $data = $definition !== null ? ($datasets[$code] ?? null) : null;
            $results[] = [
                'code' => $code,
                'widget' => $definition,
                'data' => $data,
                'error' => $definition === null ? ['reason' => 'widget_unavailable', 'message' => '仪表盘组件不存在或无权访问。']
                    : ($errors[$code] ?? ($data === null ? ['reason' => 'data_missing', 'message' => '仪表盘组件尚未提供数据。'] : null)),
                'queried_at' => (int) floor(microtime(true) * 1000),
            ];
        }

        return ['results' => $results];
    }

    /** @return array<string,mixed> */
    public function summary(bool $synchronized = false): array
    {
        return $this->builtin->summary($synchronized);
    }

    /** @return array<string,array<string,list<string>>> 插件 => 已授权入口 => 区块编码。 */
    public function frontendReferences(Admin $admin): array
    {
        $references = [];
        foreach ($this->availableWidgets($admin) as $widget) {
            if ($widget['type'] === 'custom') {
                $references[$widget['addon_code']][$widget['renderer']['entry']][] = $widget['code'];
            }
        }

        return $references;
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

    /** @param array<string,array{definition:array<string,mixed>,provider:?string}>|null $entries @return list<array<string,mixed>> */
    private function availableWidgets(Admin $admin, ?array $entries = null): array
    {
        $configured = array_map(static fn (array $entry): array => $entry['definition'], $entries ?? $this->registry->entries());
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
            $widgets[] = [...$widget, 'enabled' => $widget['default_enabled']];
        }
        usort($widgets, static fn (array $left, array $right): int => ($left['sort'] ?? 0) <=> ($right['sort'] ?? 0));

        return $widgets;
    }

    private function overviewCode(Admin $admin): string
    {
        $code = (string) config('ptadmin-dashboard.overview_widget', 'ptadmin.workspace-summary');
        $definitions = array_column($this->availableWidgets($admin), null, 'code');

        return ($definitions[$code]['type'] ?? null) === 'stats' ? $code : '';
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
