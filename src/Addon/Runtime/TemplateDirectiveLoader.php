<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Runtime;

use Illuminate\Contracts\Foundation\Application;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Runtime\Contracts\TemplateDirectiveProviderInterface;
use PTAdmin\Template\Directive\DirectiveRegistry;

/**
 * 负责发现插件 Directives/*.php 并接入通用 Template Registry。
 *
 * 文件按路径稳定排序，所有定义先写入插件级临时 Registry；任一文件或冲突校验失败时，全局 Registry 不会
 * 留下该插件的部分指令。Template 模块因此无需了解插件目录、manifest 或 Laravel 容器。
 */
final class TemplateDirectiveLoader
{
    public function __construct(
        private Application $app,
        private DirectiveRegistry $registry,
    ) {
    }
    
    /**
     * 注册一个插件的全部模板指令；同一插件已存在定义时跳过重复加载。
     *
     * @param string $directory
     * @param string $code
     * @param array<string,mixed> $manifest 已完成验证的插件 manifest
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function register(string $directory, string $code, array $manifest): void
    {
        foreach ($this->registry->all() as $definition) {
            if ($definition->namespace() === $code) {
                return;
            }
        }

        $path = $directory.'/Directives';
        if (!is_dir($path)) {
            return;
        }

        $staging = new DirectiveRegistry();
        $files = glob($path.'/*.php') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $before = get_declared_classes();
            require_once $file;
            $directiveClasses = [];
            $declared = array_diff(get_declared_classes(), $before);
            // require_once 可能命中此前已加载的类，按 Reflection 文件路径补回本文件已有的声明。
            foreach (get_declared_classes() as $class) {
                if (in_array($class, $declared, true)) {
                    continue;
                }
                try {
                    $reflection = new \ReflectionClass($class);
                } catch (\ReflectionException) {
                    continue;
                }
                if ($reflection->getFileName() !== $file) {
                    continue;
                }
                $declared[] = $class;
            }
            foreach ($declared as $class) {
                if (is_a($class, TemplateDirectiveProviderInterface::class, true)) {
                    $directiveClasses[] = $class;
                }
            }
            if ($directiveClasses === []) {
                throw new AddonException('Addon directive file must declare TemplateDirectiveProviderInterface.', 'invalid_directives');
            }
            $registration = $staging->forNamespace($code);
            foreach ($directiveClasses as $class) {
                $this->app->make($class)->register($registration, $manifest);
            }
            $registration->commit();
        }

        $this->registry->commitBatch(array_values($staging->all()));
    }
}
