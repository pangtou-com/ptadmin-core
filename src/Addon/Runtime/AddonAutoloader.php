<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Runtime;

use Composer\Autoload\ClassLoader;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Manifest\AddonNamespace;

/** 插件共用独立的 Composer 加载器；映射只覆盖包内 src，不修改宿主加载器。 */
final class AddonAutoloader
{
    /** @var array<string, string> 含停用插件的 namespace 身份占用。 */
    private array $namespaces = [];

    /** @var array<string, string> 当前可加载插件的 src 绝对路径。 */
    private array $directories = [];

    private ?ClassLoader $loader = null;

    /** @param array<string, mixed> $manifest 已验证的 manifest */
    public function reserve(array $manifest): string
    {
        $code = (string) $manifest['code'];
        $namespace = AddonNamespace::fromManifest($manifest);
        $prefix = strtolower($namespace).'\\';
        foreach ($this->namespaces as $owner => $existing) {
            if ($owner === $code) {
                if ($existing !== $namespace) {
                    throw new AddonException('插件 namespace 不能在当前应用中变更：'.$code, 'addon_namespace_changed');
                }
                continue;
            }
            $existingPrefix = strtolower($existing).'\\';
            if (str_starts_with($prefix, $existingPrefix) || str_starts_with($existingPrefix, $prefix)) {
                throw new AddonException('插件 namespace 冲突：'.$code.' / '.$owner, 'addon_namespace_conflict');
            }
        }
        $this->namespaces[$code] = $namespace;

        return $namespace;
    }

    /**
     * 在迁移、回调或类检查之前调用；只有前端或资源的插件可以没有 src。
     * $refresh 用于同路径替换代码的更新操作，清除 Composer 的缺失类缓存。
     *
     * @param array<string, mixed> $manifest 已验证的 manifest
     */
    public function register(string $directory, array $manifest, bool $refresh = false): string
    {
        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            throw new AddonException('插件目录不存在：'.$manifest['code'], 'addon_directory_unavailable');
        }
        $path = $root.'/src';
        $source = null;
        if (file_exists($path)) {
            $source = realpath($path);
            if ($source === false || !is_dir($source) || !str_starts_with($source, $root.DIRECTORY_SEPARATOR)) {
                throw new AddonException('插件 src 必须位于插件目录内：'.$manifest['code'], 'unsafe_autoload_path');
            }
        }
        $namespace = $this->reserve($manifest);
        $code = (string) $manifest['code'];
        if (!$refresh && ($this->directories[$code] ?? null) === $source) {
            return $namespace;
        }
        if ($source === null) {
            unset($this->directories[$code]);
        } else {
            $this->directories[$code] = $source;
        }
        $this->rebuild();

        return $namespace;
    }

    /**
     * 保存操作前的插件映射，供失败补偿恢复。
     *
     * @return array{namespace:string,directory:?string}|null
     */
    public function snapshot(string $code): ?array
    {
        if (!isset($this->namespaces[$code])) {
            return null;
        }

        return ['namespace' => $this->namespaces[$code], 'directory' => $this->directories[$code] ?? null];
    }

    /** 恢复单个操作前的映射；PHP 已定义的类不受映射撤销影响。 */
    public function restore(string $code, ?array $snapshot): void
    {
        unset($this->namespaces[$code], $this->directories[$code]);
        if ($snapshot !== null) {
            $this->namespaces[$code] = $snapshot['namespace'];
            if ($snapshot['directory'] !== null) {
                $this->directories[$code] = $snapshot['directory'];
            }
        }
        $this->rebuild();
    }

    public function __destruct()
    {
        $this->loader?->unregister();
    }

    private function rebuild(): void
    {
        // Composer 会记住缺失类；重建能让安装后新增映射立即参与查找。
        $this->loader?->unregister();
        $this->loader = new ClassLoader();
        foreach ($this->directories as $code => $directory) {
            $this->loader->addPsr4($this->namespaces[$code].'\\', $directory);
        }
        if ($this->directories !== []) {
            $this->loader->register();
        }
    }
}
