<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Lifecycle;

use Illuminate\Contracts\Foundation\Application;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Manifest\AddonNamespace;
use PTAdmin\Addon\Runtime\AddonAutoloader;
use PTAdmin\Addon\Lifecycle\Contracts\LifecycleInterface;

/** 按固定 src/Lifecycle/Handler.php 约定调用插件生命周期回调。 */
final class LifecycleRegistrar
{
    public function __construct(private Application $app, private ?AddonAutoloader $autoloader = null)
    {
    }

    /**
     * 校验事件名称后调用对应生命周期，未知事件在加载插件前拒绝。
     *
     * @param array<string,mixed> $manifest
     */
    public function invoke(string $event, string $directory, string $code, array $manifest): void
    {
        if (!in_array($event, ['install', 'uninstall', 'enable', 'disable'], true)) {
            throw new AddonException('Addon lifecycle event is invalid.', 'invalid_lifecycle_event');
        }

        ($this->autoloader ??= new AddonAutoloader())->register($directory, $manifest);
        $file = $directory.'/src/Lifecycle/Handler.php';
        if (!is_file($file)) {
            return;
        }
        $class = AddonNamespace::fromManifest($manifest).'\\Lifecycle\\Handler';
        if (!class_exists($class, false)) {
            require_once $file;
        }
        if (!class_exists($class, false) || !is_a($class, LifecycleInterface::class, true)) {
            throw new AddonException('Addon lifecycle handler is invalid.', 'invalid_lifecycle_handler');
        }

        $handler = $this->app->make($class);
        $handler->{$event}(new LifecycleContext($code, $directory, $manifest));
    }

}
