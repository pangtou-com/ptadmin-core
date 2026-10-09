<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

/** 本地发布配置；命令只在开发者发起发布后执行，平台不执行包内脚本。 */
final class ReleaseConfiguration
{
    public const FILE = 'ptadmin.release.json';

    public function __construct(private readonly array $values)
    {
        if (array_diff(array_keys($values), ['build', 'install', 'source']) !== []) {
            throw new CloudException('发布配置包含未知字段。', 'invalid_request');
        }
        foreach (['install', 'source'] as $kind) {
            $rules = $values[$kind] ?? [];
            if (!is_array($rules) || array_diff(array_keys($rules), ['include', 'exclude']) !== []) {
                throw new CloudException('发布配置 '.$kind.' 只支持 include 和 exclude。', 'invalid_request');
            }
            foreach ($rules as $patterns) {
                if (!is_array($patterns) || !array_is_list($patterns)) {
                    throw new CloudException('发布文件规则必须是路径模式列表。', 'invalid_request');
                }
                foreach ($patterns as $pattern) {
                    if (!is_string($pattern) || !self::safePath($pattern) || str_contains($pattern, '!')) {
                        throw new CloudException('发布文件规则包含无效路径。', 'invalid_request');
                    }
                }
            }
        }
    }

    public static function load(string $root): self
    {
        $path = $root.'/'.self::FILE;
        $values = is_file($path) ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
        if (!is_array($values)) {
            throw new CloudException('发布配置必须是 JSON 对象。', 'invalid_request');
        }
        return new self($values);
    }

    /** @return null|array{directory:string,command:list<string>,output:string} */
    public function build(string $root, string $type, bool $skipBuild = false): ?array
    {
        $build = $this->values['build'] ?? null;
        if ($build === null) {
            $directory = is_file($root.'/frontend/package.json') ? 'frontend' : '.';
            $path = $root.'/'.$directory.'/package.json';
            if (!is_file($path)) {
                if (!$skipBuild && is_dir($root.'/frontend/src')) {
                    throw new CloudException('存在前端源码但未声明构建步骤，请配置 '.self::FILE.'。', 'invalid_request');
                }
                return null;
            }
            $package = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($package) || array_is_list($package) || !is_array($package['scripts'] ?? null)
                || !is_string($package['scripts']['build'] ?? null)
                || (isset($package['packageManager']) && !is_string($package['packageManager']))) {
                throw new CloudException('package.json 缺少 build 脚本，请配置发布构建步骤。', 'invalid_request');
            }
            $manager = isset($package['packageManager']) ? explode('@', $package['packageManager'])[0] : null;
            if ($manager === null) {
                $managers = [];
                foreach (['pnpm-lock.yaml' => 'pnpm', 'yarn.lock' => 'yarn', 'package-lock.json' => 'npm', 'bun.lock' => 'bun', 'bun.lockb' => 'bun'] as $lock => $candidate) {
                    if (is_file($root.'/'.$directory.'/'.$lock)) {
                        $managers[] = $candidate;
                    }
                }
                $managers = array_unique($managers);
                if (count($managers) > 1) {
                    throw new CloudException('存在多个包管理器锁文件，请在 packageManager 中明确构建工具。', 'invalid_request');
                }
                $manager = $managers === [] ? 'npm' : reset($managers);
            }
            if (!in_array($manager, ['npm', 'pnpm', 'yarn', 'bun'], true)) {
                throw new CloudException('请通过发布配置显式指定构建命令。', 'invalid_request');
            }
            $build = ['directory' => $directory, 'command' => [$manager, 'run', 'build'],
                'output' => $type === 'addon' ? 'frontend/dist' : 'assets/build'];
        }
        if (!is_array($build) || array_diff(array_keys($build), ['directory', 'command', 'output']) !== []
            || !is_string($build['directory'] ?? null) || !self::safePath($build['directory'], true)
            || !is_string($build['output'] ?? null) || !self::safePath($build['output']) || str_ends_with($build['output'], '/')
            || !is_array($build['command'] ?? null) || !array_is_list($build['command']) || $build['command'] === []) {
            throw new CloudException('build 必须声明 directory、命令参数数组 command 和产物目录 output。', 'invalid_request');
        }
        foreach ($build['command'] as $argument) {
            if (!is_string($argument) || $argument === '' || str_contains($argument, "\0")) {
                throw new CloudException('构建命令参数必须是非空字符串。', 'invalid_request');
            }
        }
        if ($type === 'addon' && $build['output'] !== 'frontend/dist') {
            throw new CloudException('插件构建产物必须输出到 frontend/dist。', 'invalid_request');
        }
        if ($type === 'template' && $build['output'] !== 'assets' && !str_starts_with($build['output'], 'assets/')) {
            throw new CloudException('模板构建产物必须输出到 assets 或其子目录。', 'invalid_request');
        }
        return $build;
    }

    public function includes(string $path, string $kind): bool
    {
        $rules = $this->values[$kind] ?? [];
        return (!isset($rules['include']) || $this->matches($path, $rules['include']))
            && !$this->matches($path, $rules['exclude'] ?? []);
    }

    /** * 匹配单层，** 匹配跨层路径；目录名本身同时匹配其全部后代。 */
    private function matches(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $quoted = preg_quote(rtrim($pattern, '/'), '#');
            $expression = str_replace(['\*\*/', '\*\*', '\*', '\?'], ['(?:.*/)?', '.*', '[^/]*', '[^/]'], $quoted);
            if (preg_match('#^'.$expression.'(?:/.*)?$#D', $path) === 1) {
                return true;
            }
        }
        return false;
    }

    public static function safePath(string $path, bool $allowRoot = false): bool
    {
        return ($allowRoot && $path === '.') || ($path !== '' && !str_starts_with($path, '/')
            && preg_match('/[\\\\:\x00-\x1f]/', $path) !== 1
            && array_intersect(['', '.', '..'], explode('/', rtrim($path, '/'))) === []);
    }
}
