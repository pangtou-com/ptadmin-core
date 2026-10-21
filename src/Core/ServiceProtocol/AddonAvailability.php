<?php

declare(strict_types=1);

namespace PTAdmin\Core\ServiceProtocol;

use PTAdmin\Addon\Catalog\AddonCatalog;

final class AddonAvailability
{
    public function __construct(private readonly AddonCatalog $catalog)
    {
    }

    public function active(string $addonCode): bool
    {
        Input::code($addonCode, 'addon_code');
        foreach ($this->catalog->scan()['addons'] as $addon) {
            if ($addon['code'] === $addonCode) {
                return $addon['state'] === 'active';
            }
        }
        return false;
    }

    public function assertActive(string $addonCode): void
    {
        if (!$this->active($addonCode)) {
            throw new ProtocolException('addon_unavailable', "插件 {$addonCode} 未启用，拒绝协议调用。");
        }
    }
}
