<?php

declare(strict_types=1);

namespace PTAdmin\Core\ServiceProtocol;

use PTAdmin\Addon\Lifecycle\LifecycleStore;

final class AddonAvailability
{
    public function __construct(private LifecycleStore $lifecycle)
    {
    }

    public function active(string $addonCode): bool
    {
        Input::code($addonCode, 'addon_code');
        return ($this->lifecycle->read($addonCode)['state'] ?? null) === 'active';
    }

    public function assertActive(string $addonCode): void
    {
        if (!$this->active($addonCode)) {
            throw new ProtocolException('addon_unavailable', "插件 {$addonCode} 未启用，拒绝协议调用。");
        }
    }
}
