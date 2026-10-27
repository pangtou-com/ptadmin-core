<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Dashboard;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Arr;

/** 区块定义及业务提供器返回值的协议边界，不向渲染层传递未验证字段。 */
final class DashboardWidgetValidator
{
    public const TYPES = ['stats', 'trend', 'ranking', 'list', 'card', 'custom'];

    /** @param array<string,mixed> $definition @return array<string,mixed> */
    public function definition(string $code, array $definition, bool $addon = false): array
    {
        $validated = $this->validate(['code' => $code] + $definition, [
            'code' => ['required', 'string', 'max:120', 'regex:/^[a-z][a-z0-9_-]*\.[a-z0-9_.-]+$/'],
            'title' => 'required|string|max:120',
            'type' => 'required|in:'.implode(',', self::TYPES),
            'description' => 'sometimes|string|max:500',
            'icon' => 'sometimes|string|max:120',
            'group' => 'sometimes|string|max:120',
            'permission' => ($addon ? 'required' : 'sometimes').'|string|max:200|regex:/^[a-zA-Z0-9_.-]+\.[a-zA-Z0-9_-]+$/',
            'permissions_any' => 'sometimes|array|min:1|max:20',
            'permissions_any.*' => 'required|string|max:200|distinct|regex:/^[a-zA-Z0-9_.-]+\.[a-zA-Z0-9_-]+$/',
            'sort' => 'sometimes|integer|min:0|max:10000',
            'enabled' => 'sometimes|boolean',
            'default_enabled' => 'sometimes|boolean',
            'default_layout' => 'sometimes|array',
        ], $code.' 定义');
        if (isset($validated['permissions_any']) && !array_is_list($validated['permissions_any'])) {
            throw new \InvalidArgumentException('仪表盘 '.$code.' permissions_any 必须是数组。');
        }
        if ($validated['type'] === 'custom') {
            if (!$addon) {
                throw new \InvalidArgumentException('仪表盘自定义内容仅允许插件注册：'.$code);
            }
            $validated += $this->validate($definition, [
                'renderer' => 'required|array:entry',
                'renderer.entry' => ['required', 'string', 'max:191', 'regex:/^[a-z][a-z0-9_-]*\.[a-zA-Z0-9_.-]+$/'],
            ], $code.' 自定义入口');
        }
        $layout = array_replace([
            'x' => 0, 'y' => 0, 'w' => 6, 'h' => 4, 'min_w' => 4, 'min_h' => 3, 'max_w' => 12, 'max_h' => 8,
        ], $definition['default_layout'] ?? []);
        $validated['default_layout'] = $this->layout($layout, $code);
        $validated['default_enabled'] = (bool) ($definition['default_enabled'] ?? !$addon);
        if (isset($validated['enabled'])) {
            $validated['enabled'] = (bool) $validated['enabled'];
        }
        if ($addon) {
            // 安装插件只增加候选项，不改变管理员已有工作区。
            $validated['default_enabled'] = false;
        }

        return $validated;
    }

    /** @return array<string,mixed> */
    public function layout(mixed $layout, string $code, array $bounds = []): array
    {
        $validated = $this->validate(['layout' => $layout], [
            'layout' => 'present|array:x,y,w,h,min_w,min_h,max_w,max_h',
            'layout.x' => 'sometimes|integer|min:0|max:11',
            'layout.y' => 'sometimes|integer|min:0|max:10000',
            'layout.w' => 'sometimes|integer|min:1|max:12',
            'layout.h' => 'sometimes|integer|min:1|max:24',
            'layout.min_w' => 'sometimes|integer|min:1|max:12',
            'layout.max_w' => 'sometimes|integer|min:1|max:12',
            'layout.min_h' => 'sometimes|integer|min:1|max:24',
            'layout.max_h' => 'sometimes|integer|min:1|max:24',
        ], $code.' 布局')['layout'];
        foreach (['w', 'h'] as $dimension) {
            $min = $bounds['min_'.$dimension] ?? $validated['min_'.$dimension] ?? 1;
            $max = $bounds['max_'.$dimension] ?? $validated['max_'.$dimension] ?? ($dimension === 'w' ? 12 : 24);
            $value = $validated[$dimension] ?? $min;
            if ($min > $max || $value < $min || $value > $max) {
                throw new \InvalidArgumentException('仪表盘 '.$code.' 布局尺寸超出定义范围。');
            }
            if ($bounds !== []) {
                $validated['min_'.$dimension] = $min;
                $validated['max_'.$dimension] = $max;
            }
        }

        return $validated;
    }

    /** @return array<string,mixed> */
    public function config(mixed $config, string $code): array
    {
        return $this->validate(['config' => $config], [
            'config' => 'present|array:color',
            'config.color' => ['sometimes', 'string', 'regex:/^(#[0-9a-fA-F]{6}|var\(--pt-primary\))$/'],
        ], $code.' 配置')['config'];
    }

    /** @return array<string,mixed> */
    public function dataset(mixed $data, string $type, string $code): array
    {
        if (!is_array($data)) {
            throw new \InvalidArgumentException('仪表盘 '.$code.' 数据必须是对象。');
        }
        if ($type === 'custom') {
            return $this->validate($data, ['type' => 'required|in:custom'], $code.' 数据');
        }
        $rules = ['type' => 'required|in:'.$type];
        if ($type === 'trend') {
            $rules += [
                'categories' => 'present|array|max:60', 'categories.*' => 'present|string|max:200',
                'series' => 'present|array|max:60', 'series.*' => ['required', $this->trendValueRule()],
                'chart' => 'sometimes|in:line,bar', 'unit' => 'sometimes|string|max:30',
                'period_label' => 'sometimes|string|max:120',
                'metrics' => 'sometimes|array|min:1|max:4',
                'metrics.*' => 'required|array',
                'metrics.*.id' => 'required|string|max:120|distinct',
                'metrics.*.label' => 'required|string|max:200',
                'metrics.*.series' => 'present|array|max:60',
                'metrics.*.series.*' => ['required', $this->trendValueRule()],
                'metrics.*.unit' => 'sometimes|string|max:30',
            ];
        } else {
            $rules += ['items' => 'present|array|max:'.($type === 'stats' ? 20 : 100), 'items.*' => 'required|array'];
            $rules += match ($type) {
                'stats' => [
                    'items.*.id' => 'sometimes|string|max:120|distinct', 'items.*.label' => 'required|string|max:200',
                    'items.*.value' => ['present', $this->scalarRule()], 'items.*.unit' => 'sometimes|string|max:30',
                    'items.*.comparison' => 'sometimes|array|min:2',
                    'items.*.comparison.label' => 'required_with:items.*.comparison|string|max:120',
                    'items.*.comparison.value' => 'required_with:items.*.comparison|string|max:120',
                    'items.*.comparison.tone' => 'sometimes|in:default,success,warning,danger',
                    'items.*.description' => 'sometimes|string|max:500', 'items.*.icon' => 'sometimes|string|max:120',
                    'items.*.tone' => 'sometimes|in:default,success,warning,danger',
                    'items.*.target' => ['sometimes', 'string', 'max:500', 'regex:~^/(?!/)[^\x00-\x20\x7F\\\\]*$~D'],
                ],
                'ranking' => [
                    'items.*.id' => 'required|string|max:120|distinct', 'items.*.label' => 'required|string|max:200',
                    'items.*.value' => ['present', $this->scalarRule()], 'items.*.change' => 'sometimes|string|max:120',
                ],
                'list' => [
                    'items.*.id' => 'required|string|max:120|distinct', 'items.*.title' => 'required|string|max:200',
                    'items.*.meta' => 'sometimes|string|max:500', 'items.*.status' => 'sometimes|in:default,success,warning,danger',
                    'items.*.target' => ['sometimes', 'string', 'max:500', 'regex:~^/(?!/)[^\x00-\x20\x7F\\\\]*$~D'],
                ],
                'card' => [
                    'items.*.id' => 'required|string|max:120|distinct', 'items.*.label' => 'required|string|max:200',
                    'items.*.description' => 'sometimes|string|max:500', 'items.*.icon' => 'sometimes|string|max:120',
                    'items.*.disabled' => 'sometimes|boolean',
                    'items.*.target' => ['sometimes', 'string', 'max:500', 'regex:~^/(?!/)[^\x00-\x20\x7F\\\\]*$~D'],
                ],
                default => throw new \InvalidArgumentException('仪表盘不支持的内容类型：'.$type),
            };
        }
        $validated = $this->validate($data, $rules, $code.' 数据');
        $list = $type === 'trend' ? $validated['series'] : $validated['items'];
        if (!array_is_list($list)) {
            throw new \InvalidArgumentException('仪表盘 '.$code.' 数据条目必须是数组。');
        }
        if ($type === 'trend') {
            $this->validateSeries($validated['categories'], $list, $code);
            if (isset($validated['metrics'])) {
                if (!array_is_list($validated['metrics'])) {
                    throw new \InvalidArgumentException('仪表盘 '.$code.' metrics 必须是数组。');
                }
                foreach ($validated['metrics'] as $metric) {
                    $this->validateSeries($validated['categories'], $metric['series'], $code);
                }
                $first = $validated['metrics'][0];
                if ($first['series'] !== $list || ($first['unit'] ?? '') !== ($validated['unit'] ?? '')) {
                    throw new \InvalidArgumentException('仪表盘 '.$code.' 默认指标必须与顶层趋势一致。');
                }
                $validated['metrics'] = array_map(static fn (array $metric): array => Arr::only($metric, ['id', 'label', 'series', 'unit']), $validated['metrics']);
            }
        } else {
            // Laravel 的数组父级规则会保留额外字段，此处显式投影协议条目。
            $fields = array_map(static fn (string $field): string => substr($field, 8), array_filter(array_keys($rules), static fn (string $field): bool => str_starts_with($field, 'items.*.')));
            $validated['items'] = array_map(static function (array $item) use ($fields): array {
                $item = Arr::only($item, $fields);
                if (isset($item['comparison'])) {
                    $item['comparison'] = Arr::only($item['comparison'], ['label', 'value', 'tone']);
                }

                return $item;
            }, $list);
        }

        return Arr::only($validated, $type === 'trend' ? ['type', 'categories', 'series', 'chart', 'unit', 'period_label', 'metrics'] : ['type', 'items']);
    }

    /** @param array<mixed> $categories @param array<mixed> $series */
    private function validateSeries(array $categories, array $series, string $code): void
    {
        if (!array_is_list($categories) || !array_is_list($series) || count($categories) !== count($series)
            || !is_finite(array_sum($series))) {
            throw new \InvalidArgumentException('仪表盘 '.$code.' 趋势标签与有限数值必须一一对应。');
        }
    }

    private function trendValueRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) {
                $fail($attribute.' 必须是非负有限数值。');
            }
        };
    }

    private function scalarRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (!(is_string($value) && mb_strlen($value) <= 2000)
                && !(is_int($value) || is_float($value) && is_finite($value))) {
                $fail($attribute.' 必须是有限数值或不超过 2000 字符的文本。');
            }
        };
    }

    /** @return array<string,mixed> */
    private function validate(array $input, array $rules, string $context): array
    {
        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            throw new \InvalidArgumentException('仪表盘 '.$context.' 校验失败：'.$validator->errors()->first());
        }

        return $validator->validated();
    }
}
