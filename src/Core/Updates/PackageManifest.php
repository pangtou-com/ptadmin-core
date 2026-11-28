<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

use RuntimeException;

/** 官方应用产物的文件协议；不加载归档中的 PHP，构建端、平台与独立更新进程共用。 */
final class PackageManifest
{
    public const PROTOCOL = 2;
    public const IDENTITY = 'bootstrap/ptadmin-release.json';

    public static function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $entry) {
                $value[$key] = json_decode(self::canonical($entry), true, 128, JSON_THROW_ON_ERROR);
            }
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function digest(mixed $value): string
    {
        return hash('sha256', self::canonical($value));
    }

    public static function path(string $path): void
    {
        if ($path === '' || strlen($path) > 1024 || preg_match('/[\\\\:\x00-\x1f]/', $path)
            || str_starts_with($path, '/') || array_intersect(explode('/', $path), ['', '.', '..']) !== []) {
            throw new RuntimeException('发行包路径无效：'.$path);
        }
    }

    public static function managed(string $path): bool
    {
        return in_array($path, ['composer.json', 'composer.lock'], true) || str_starts_with($path, 'vendor/');
    }

    /** Composer root 的说明信息不改变依赖资格；其余未知配置也纳入基线，默认不放行。 */
    public static function composer(array $composer): array
    {
        return array_diff_key($composer, array_flip(['name', 'description', 'keywords', 'homepage', 'license', 'authors', 'support', 'funding', '$schema']));
    }

    public static function lock(array $lock): array
    {
        unset($lock['content-hash'], $lock['_readme']);
        return $lock;
    }

    public static function platform(array $composer, array $lock): array
    {
        $requirements = [];
        foreach ([$composer, ...($lock['packages'] ?? [])] as $definition) {
            foreach ($definition['require'] ?? [] as $name => $constraint) {
                if (in_array($name, ['php', 'php-64bit'], true) || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-')) {
                    $requirements[$name][] = $constraint;
                }
            }
        }
        foreach ($requirements as &$constraints) {
            $constraints = array_values(array_unique($constraints));
            sort($constraints);
        }
        unset($constraints);
        ksort($requirements);
        return $requirements;
    }

    public static function fingerprint(string $root, string $path): string
    {
        return match ($path) {
            'composer.json' => self::digest(self::composer(UpdateFiles::read($root.'/'.$path))),
            'composer.lock' => self::digest(self::lock(UpdateFiles::read($root.'/'.$path))),
            default => hash_file('sha256', $root.'/'.$path),
        };
    }

    /** @return array<string, array{size:int,sha256:string}> */
    public static function files(string $root, array $paths = ['vendor', 'composer.json', 'composer.lock']): array
    {
        $files = [];
        foreach ($paths as $path) {
            self::path($path);
            if (is_link($root.'/'.$path)) {
                throw new RuntimeException('发行文件不能为符号链接：'.$path);
            }
            if (is_dir($root.'/'.$path)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$path, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isLink() || !$file->isFile()) {
                        throw new RuntimeException('发行目录包含特殊文件：'.$file->getPathname());
                    }
                    $relative = substr($file->getPathname(), strlen($root) + 1);
                    self::path($relative);
                    $files[$relative] = ['size' => $file->getSize(), 'sha256' => self::fingerprint($root, $relative)];
                }
            } elseif (is_file($root.'/'.$path)) {
                $files[$path] = ['size' => filesize($root.'/'.$path), 'sha256' => self::fingerprint($root, $path)];
            } else {
                throw new RuntimeException('发行文件不存在：'.$path);
            }
        }
        ksort($files);
        return $files;
    }

    public static function validate(array $target): void
    {
        foreach (['build_id', 'tree_sha256', 'baseline_sha256', 'core_commit', 'admin_commit'] as $key) {
            $length = str_ends_with($key, 'commit') ? 40 : 64;
            if (!is_string($target[$key] ?? null) || !preg_match('/^[a-f0-9]{'.$length.'}$/D', $target[$key])) {
                throw new RuntimeException('发行身份字段无效：'.$key);
            }
        }
        if (($target['protocol_version'] ?? null) !== self::PROTOCOL || !is_array($target['files'] ?? null)
            || !is_array($target['baseline'] ?? null) || !is_array($target['host_files'] ?? null)
            || !is_array($target['platform'] ?? null) || !is_array($target['shell'] ?? null)
            || ($target['profile'] ?? null) !== 'php-standard' || !is_string($target['upgrade_from'] ?? null) || $target['upgrade_from'] === ''
            || !ReleaseVersion::valid($target['version'] ?? null) || !ReleaseVersion::stable($target['version'] ?? '') || count($target['files']) > 100000) {
            throw new RuntimeException('应用发行清单结构无效。');
        }
        $seen = [];
        foreach ($target['files'] as $path => $file) {
            self::path($path);
            if (!self::managed($path) || isset($seen[strtolower($path)]) || !is_int($file['size'] ?? null) || $file['size'] < 0
                || !is_string($file['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $file['sha256'])) {
                throw new RuntimeException('受管文件清单无效：'.$path);
            }
            $seen[strtolower($path)] = true;
        }
        foreach (array_keys($seen) as $path) {
            for ($parent = dirname($path); $parent !== '.'; $parent = dirname($parent)) {
                if (isset($seen[$parent])) {
                    throw new RuntimeException('发行文件与目录路径冲突：'.$path);
                }
            }
        }
        foreach (['composer.json', 'composer.lock', 'vendor/autoload.php', 'vendor/composer/installed.php', 'vendor/composer/InstalledVersions.php', 'vendor/composer/installed.json'] as $required) {
            if (!isset($target['files'][$required])) {
                throw new RuntimeException('缺少必要发行文件：'.$required);
            }
        }
        if (($target['managed_bytes'] ?? null) !== array_sum(array_column($target['files'], 'size'))) {
            throw new RuntimeException('目标展开大小与清单不一致。');
        }
        foreach (['public/index.php', 'artisan', 'bootstrap/update-guard.php', 'bootstrap/app.php', 'config/auth.php'] as $path) {
            if (!isset($target['host_files'][$path])) {
                throw new RuntimeException('缺少宿主前置约束：'.$path);
            }
        }
        foreach ($target['host_files'] as $path => $sha) {
            self::path($path);
            if (self::managed($path) || !is_string($sha) || !preg_match('/^[a-f0-9]{64}$/D', $sha)) {
                throw new RuntimeException('宿主前置文件无效。');
            }
        }
        if (!is_array($target['baseline']['composer'] ?? null) || !is_array($target['baseline']['lock'] ?? null)
            || self::digest(self::platform($target['baseline']['composer'], $target['baseline']['lock'])) !== self::digest($target['platform'])) {
            throw new RuntimeException('目标平台要求与生产依赖基线不一致。');
        }
        $packages = array_column($target['baseline']['lock']['packages'] ?? [], null, 'name');
        $core = $packages['ptadmin/core'] ?? [];
        if (ltrim($core['version'] ?? '', 'v') !== $target['version'] || ($core['source']['reference'] ?? null) !== $target['core_commit']) {
            throw new RuntimeException('目标 Core 与依赖基线不一致。');
        }
        if (!ReleaseVersion::valid($target['shell']['version'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $target['shell']['artifact_sha256'] ?? '')
            || !is_string($target['shell']['backend_constraint'] ?? null)
            || !is_int($target['shell']['api_protocol'] ?? null) || !is_int($target['shell']['schema_protocol'] ?? null)) {
            throw new RuntimeException('配套 Shell 身份无效。');
        }
        $identity = $target;
        unset($identity['build_id']);
        if (self::digest($identity) !== $target['build_id'] || self::digest($target['files']) !== $target['tree_sha256']
            || self::digest($target['baseline']) !== $target['baseline_sha256']) {
            throw new RuntimeException('发行清单身份摘要不一致。');
        }
    }

    public static function verify(string $root, array $target, bool $host = true): void
    {
        self::validate($target);
        $composer = self::composer(UpdateFiles::read($root.'/composer.json'));
        $lock = self::lock(UpdateFiles::read($root.'/composer.lock'));
        if (self::digest(['composer' => $composer, 'lock' => $lock]) !== $target['baseline_sha256']) {
            throw new RuntimeException('实际 Composer 文件与目标依赖基线不一致。');
        }
        $installed = UpdateFiles::read($root.'/vendor/composer/installed.json');
        $graph = static function (array $packages): array {
            $result = [];
            foreach ($packages as $package) {
                if (isset($result[$package['name']])) {
                    throw new RuntimeException('生产依赖包含重复包。');
                }
                $result[$package['name']] = [$package['version'], $package['source']['reference'] ?? null, $package['dist']['reference'] ?? null];
            }
            ksort($result);
            return $result;
        };
        if (($installed['dev'] ?? true) !== false || $graph($installed['packages'] ?? []) !== $graph($lock['packages'] ?? [])) {
            throw new RuntimeException('生产 vendor 与锁文件不一致。');
        }
        $actual = self::files($root);
        if (array_keys($actual) !== array_keys($target['files'])) {
            throw new RuntimeException('受管依赖文件缺失或包含额外文件。');
        }
        foreach ($actual as $path => $file) {
            if ($file['sha256'] !== $target['files'][$path]['sha256']
                || (!in_array($path, ['composer.json', 'composer.lock'], true) && $file['size'] !== $target['files'][$path]['size'])) {
                throw new RuntimeException('受管文件与官方构建不一致：'.$path);
            }
        }
        if ($host) {
            foreach ($target['host_files'] as $path => $sha) {
                for ($parent = dirname($path); $parent !== '.'; $parent = dirname($parent)) {
                    if (is_link($root.'/'.$parent)) {
                        throw new RuntimeException('宿主前置目录不能为符号链接：'.$parent);
                    }
                }
                if (is_link($root.'/'.$path) || !is_file($root.'/'.$path) || hash_file('sha256', $root.'/'.$path) !== $sha) {
                    throw new RuntimeException('宿主文件需要人工合并：'.$path);
                }
            }
        }
    }
}
