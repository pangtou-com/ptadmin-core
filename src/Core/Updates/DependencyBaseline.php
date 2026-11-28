<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

/** 标准依赖资格与服务器是否有 Composer 无关。 */
final class DependencyBaseline
{
    public function inspect(?string $root = null, bool $verifyFiles = false): array
    {
        $root ??= base_path();
        try {
            if (!is_file($root.'/'.PackageManifest::IDENTITY)) {
                return ['status' => 'unknown', 'reason' => '缺少新发行协议的官方构建身份，请使用项目部署流程。', 'build_id' => null];
            }
            $target = UpdateFiles::read($root.'/'.PackageManifest::IDENTITY);
            PackageManifest::validate($target);
            $composer = PackageManifest::composer(UpdateFiles::read($root.'/composer.json'));
            $lock = PackageManifest::lock(UpdateFiles::read($root.'/composer.lock'));
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
            $installed = UpdateFiles::read($root.'/vendor/composer/installed.json');
            if (($installed['dev'] ?? true) !== false) {
                return ['status' => 'custom', 'reason' => '当前包含开发依赖，请通过项目部署流程更新。', 'build_id' => $target['build_id']];
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
