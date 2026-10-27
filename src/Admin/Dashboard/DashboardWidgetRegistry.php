<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Dashboard;

use PTAdmin\Addon\Runtime\AddonRegistration;
use PTAdmin\Core\ServiceProtocol\AddonAvailability;

/** 注册表只保存声明与服务端绑定；每次读取重新检查插件可用性。 */
final class DashboardWidgetRegistry
{
    /** @var array<string,array{widgets:array<string,array<string,mixed>>,provider:?string,token:object}> */
    private array $addons = [];

    public function __construct(
        private readonly AddonRegistration $registration,
        private readonly AddonAvailability $availability,
        private readonly DashboardWidgetValidator $validator,
    ) {
    }

    /** @param array<string,array<string,mixed>> $definitions */
    public function registerAddon(string $addonCode, array $definitions, ?string $provider = null): void
    {
        $this->registration->assertOwner($addonCode);
        $this->availability->assertActive($addonCode);
        if (($addonCode === 'ptadmin' || str_starts_with($addonCode, 'ptadmin.')) || $definitions === [] || count($definitions) > 100
            || ($provider !== null && !is_a($provider, DashboardDataProviderInterface::class, true))) {
            throw new \InvalidArgumentException('仪表盘插件注册无效：'.$addonCode.'；需要组件定义及 DashboardDataProviderInterface 提供器。');
        }
        $widgets = [];
        foreach ($definitions as $code => $definition) {
            if (!str_starts_with($code, $addonCode.'.')) {
                throw new \InvalidArgumentException('仪表盘组件编码必须属于当前插件：'.$addonCode.'/'.$code);
            }
            $widgets[$code] = $this->validator->definition($code, $definition, true) + [
                'addon_code' => $addonCode, 'source' => ['type' => 'addon', 'code' => $addonCode],
            ];
            if ($widgets[$code]['type'] === 'custom') {
                if (!str_starts_with($widgets[$code]['renderer']['entry'], $addonCode.'.')) {
                    throw new \InvalidArgumentException('仪表盘自定义入口必须属于当前插件：'.$code);
                }
            } elseif ($provider === null) {
                throw new \InvalidArgumentException('仪表盘标准组件需要数据提供器：'.$code);
            }
        }
        $entry = ['widgets' => $widgets, 'provider' => $provider, 'token' => new \stdClass()];
        $this->registration->stage($addonCode, self::class.':'.spl_object_id($this),
            function () use ($addonCode, $entry): void {
                $configured = (array) config('ptadmin-dashboard.widgets', []);
                if (isset($this->addons[$addonCode]) || array_intersect_key($configured, $entry['widgets']) !== []) {
                    throw new \InvalidArgumentException('仪表盘组件注册冲突：'.$addonCode);
                }
                foreach ($this->addons as $registered) {
                    if (array_intersect_key($registered['widgets'], $entry['widgets']) !== []) {
                        throw new \InvalidArgumentException('仪表盘插件之间组件编码冲突：'.$addonCode);
                    }
                }
                $this->addons[$addonCode] = $entry;
            },
            function () use ($addonCode, $entry): void {
                if (($this->addons[$addonCode] ?? null) === $entry) {
                    unset($this->addons[$addonCode]);
                }
            },
        );
    }

    /** @return array<string,array{definition:array<string,mixed>,provider:?string}> */
    public function entries(): array
    {
        $entries = [];
        foreach ((array) config('ptadmin-dashboard.widgets', []) as $code => $definition) {
            $entries[$code] = [
                'definition' => $this->validator->definition($code, $definition) + [
                    'source' => ['type' => str_starts_with($code, 'ptadmin.') ? 'core' : 'host', 'code' => str_starts_with($code, 'ptadmin.') ? 'ptadmin' : 'host'],
                ],
                'provider' => config('ptadmin-dashboard.provider'),
            ];
        }
        $disabled = (array) config('ptadmin-dashboard.disabled_widgets', []);
        foreach ($this->addons as $owner => $entry) {
            if (!$this->availability->active($owner)) {
                continue;
            }
            foreach ($entry['widgets'] as $code => $definition) {
                if (isset($entries[$code])) {
                    throw new \LogicException('仪表盘宿主与插件组件编码冲突：'.$code);
                }
                if (!in_array($code, $disabled, true)) {
                    $entries[$code] = ['definition' => $definition, 'provider' => $entry['provider']];
                }
            }
        }

        return $entries;
    }
}
