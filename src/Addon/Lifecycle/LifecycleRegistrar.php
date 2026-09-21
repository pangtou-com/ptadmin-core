<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Lifecycle;

use Illuminate\Contracts\Foundation\Application;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Lifecycle\Contracts\LifecycleInterface;

/** 按固定 Lifecycle/Handler.php 约定调用插件生命周期回调。 */
final class LifecycleRegistrar
{
    public function __construct(private Application $app)
    {
    }

    /** @param 'install'|'uninstall'|'enable'|'disable' $event @param array<string,mixed> $manifest */
    public function invoke(string $event, string $directory, string $code, array $manifest): void
    {
        if (!in_array($event, ['install', 'uninstall', 'enable', 'disable'], true)) {
            throw new AddonException('Addon lifecycle event is invalid.', 'invalid_lifecycle_event');
        }

        $file = $directory.'/Lifecycle/Handler.php';
        if (!is_file($file)) {
            return;
        }
        $class = $this->addonNamespace($manifest, $code).'\\Lifecycle\\Handler';
        require_once $file;
        if (!class_exists($class) || !is_a($class, LifecycleInterface::class, true)) {
            throw new AddonException('Addon lifecycle handler is invalid.', 'invalid_lifecycle_handler');
        }

        $handler = $this->app->make($class);
        $handler->{$event}(new LifecycleContext($code, $directory, $manifest));
    }

    /** @param array<string,mixed> $manifest */
    private function addonNamespace(array $manifest, string $code): string
    {
        $namespace = trim((string) ($manifest['namespace'] ?? ''), "\\");
        if ($namespace !== '') {
            return $namespace;
        }

        $parts = preg_split('/[^A-Za-z0-9]+/', $code, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $studly = implode('', array_map(
            static fn (string $part): string => ucfirst(strtolower($part)),
            $parts,
        ));

        return 'PTAdmin\\Addons\\'.$studly;
    }
}
