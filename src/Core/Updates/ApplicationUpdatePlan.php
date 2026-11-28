<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

use Composer\Semver\Semver;

/** 官方完整包与精确来源补丁使用同一目标；来源异常不会退回覆盖更新。 */
final class ApplicationUpdatePlan
{
    public function select(array $release, array $baseline): array
    {
        if ($baseline['status'] !== 'standard') {
            throw new UpdateException($baseline['reason'], 'custom_dependencies');
        }
        $application = $release['application'] ?? null;
        if (!is_array($application) || ($application['target']['version'] ?? null) !== $release['version']) {
            throw new UpdateException('该版本尚未提供完整标准应用升级包。', 'release_unavailable');
        }
        $target = $application['target'];
        $source = $baseline['target'];
        if ($source['profile'] !== $target['profile'] || !Semver::satisfies($source['version'], $target['upgrade_from'])) {
            throw new UpdateException('当前构建不在该版本允许的直接升级范围。', 'incompatible_release');
        }
        $this->platform($target['platform']);
        foreach ($target['host_files'] as $path => $sha) {
            if (!is_file(base_path($path)) || is_link(base_path($path)) || hash_file('sha256', base_path($path)) !== $sha) {
                throw new UpdateException('目标版本需要合并宿主文件：'.$path, 'host_changes_required');
            }
        }
        $full = null;
        $patch = null;
        foreach ($application['artifacts'] as $artifact) {
            if ($artifact['kind'] === 'full') {
                $full = $artifact;
            } elseif (($baseline['installation'] ?? 'package') !== 'composer' && $artifact['kind'] === 'patch' && $artifact['source_build_id'] === $source['build_id']) {
                $patch = $artifact;
            }
        }
        if ($full === null) {
            throw new UpdateException('完整升级包尚不可获取。', 'release_unavailable');
        }
        return ['target' => $target, 'artifact' => $patch !== null && $patch['size'] < $full['size'] ? $patch : $full];
    }

    public function platform(array $requirements): void
    {
        foreach ($requirements as $name => $constraints) {
            $version = match ($name) {
                'php' => PHP_VERSION,
                'php-64bit' => PHP_INT_SIZE === 8 ? PHP_VERSION : null,
                default => str_starts_with($name, 'ext-') && extension_loaded(substr($name, 4)) ? (phpversion(substr($name, 4)) ?: '0') : null,
            };
            foreach ($constraints as $constraint) {
                if ($version === null || ($constraint !== '*' && !Semver::satisfies($version, $constraint))) {
                    throw new UpdateException('目标生产依赖要求 '.$name.' '.$constraint.'，当前环境不满足。', 'platform_mismatch');
                }
            }
        }
    }
}
