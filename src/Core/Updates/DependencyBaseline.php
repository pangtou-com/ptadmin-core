<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

/** 标准依赖资格与服务器是否有 Composer 无关。 */
final class DependencyBaseline
{
    public const COMPOSER_IDENTITY = 'storage/app/ptadmin/updates/composer-baseline.json';

    public function inspect(?string $root = null, bool $verifyFiles = false): array
    {
        $root ??= base_path();
        try {
            $official = is_file($root.'/'.PackageManifest::IDENTITY);
            if (!$official && !is_file($root.'/'.self::COMPOSER_IDENTITY)) {
                return ['status' => 'unknown', 'reason' => '尚未核验标准 Composer 安装，请点击检查更新以核对当前版本官方依赖。', 'build_id' => null];
            }
            $installed = UpdateFiles::read($root.'/vendor/composer/installed.json');
            $officialTarget = $official ? UpdateFiles::read($root.'/'.PackageManifest::IDENTITY) : null;
            if ($officialTarget !== null) {
                PackageManifest::validate($officialTarget);
            }
            $lock = PackageManifest::lock(UpdateFiles::read($root.'/composer.lock'));
            $core = array_column($lock['packages'] ?? [], null, 'name')['ptadmin/core'] ?? [];
            $receipt = null;
            if (is_file($root.'/'.self::COMPOSER_IDENTITY)) {
                try {
                    $candidate = UpdateFiles::read($root.'/'.self::COMPOSER_IDENTITY);
                    PackageManifest::validate($candidate['target']);
                    if ($candidate['target']['version'] === ltrim($core['version'] ?? '', 'v')
                        && $candidate['target']['core_commit'] === ($core['source']['reference'] ?? null)
                        && ($candidate['official_identity_sha256'] ?? null) === ($official ? hash_file('sha256', $root.'/'.PackageManifest::IDENTITY) : null)) {
                        $receipt = $candidate;
                    }
                } catch (\Throwable $error) {
                    // 成功切换到正式生产构建后，旧 Composer 记录不再是其依据。
                    if (!$official || ($installed['dev'] ?? false) === true) {
                        throw $error;
                    }
                }
            }
            $composerInstallation = $receipt !== null || !$official || ($installed['dev'] ?? false) === true;
            if ($composerInstallation && $receipt === null) {
                return ['status' => 'unknown', 'reason' => '请点击检查更新，核验当前版本官方依赖和标准安装文件。', 'build_id' => null];
            }
            $target = $receipt['target'] ?? $officialTarget;
            $composer = PackageManifest::composer(UpdateFiles::read($root.'/composer.json'));
            if (PackageManifest::digest(['composer' => $composer, 'lock' => $lock]) !== $target['baseline_sha256']) {
                $changes = [];
                $original = $target['baseline']['composer'];
                foreach (array_unique([...array_keys($original), ...array_keys($composer)]) as $key) {
                    if (PackageManifest::digest($original[$key] ?? null) === PackageManifest::digest($composer[$key] ?? null)) {
                        continue;
                    }
                    if (in_array($key, ['require', 'require-dev', 'replace', 'provide', 'conflict'], true)) {
                        foreach (array_unique([...array_keys($original[$key] ?? []), ...array_keys($composer[$key] ?? [])]) as $package) {
                            if (($original[$key][$package] ?? null) !== ($composer[$key][$package] ?? null)) {
                                $changes[] = $key.': '.$package;
                            }
                        }
                    } else {
                        $changes[] = $key;
                    }
                }
                if (PackageManifest::digest($lock) !== PackageManifest::digest($target['baseline']['lock'])) {
                    $changes[] = 'composer.lock';
                }
                return ['status' => 'custom', 'reason' => '项目依赖或自动加载配置已调整，请使用 Composer 更新。变更项：'.implode('、', $changes).'。', 'build_id' => $target['build_id']];
            }
            if ($composerInstallation) {
                if (($receipt['installed_sha256'] ?? '') !== hash_file('sha256', $root.'/vendor/composer/installed.json')) {
                    throw new \RuntimeException('核验后实际安装依赖发生变化。');
                }
                if ($verifyFiles) {
                    PackageManifest::verifyComposerInstallation($root, $target, $receipt['files']);
                }
                return ['status' => 'standard', 'reason' => '', 'build_id' => $target['build_id'], 'target' => $target,
                    'installation' => 'composer', 'development_dependencies' => $receipt['development_dependencies'],
                    'source_snapshot' => $receipt['files']];
            }
            $packages = static function (array $list): array {
                $result = [];
                foreach ($list as $package) {
                    $result[$package['name']] = [$package['version'], $package['source']['reference'] ?? null, $package['dist']['reference'] ?? null];
                }
                ksort($result);
                return $result;
            };
            if ($packages($installed['packages'] ?? []) !== $packages($lock['packages'] ?? [])) {
                throw new \RuntimeException('实际依赖与锁文件不一致。');
            }
            if ($verifyFiles) {
                PackageManifest::verify($root, $target);
            }
            return ['status' => 'standard', 'reason' => '', 'build_id' => $target['build_id'], 'target' => $target];
        } catch (\Throwable $error) {
            return ['status' => 'unknown', 'reason' => '依赖基线无法验证：'.$error->getMessage(), 'build_id' => null];
        }
    }
}
