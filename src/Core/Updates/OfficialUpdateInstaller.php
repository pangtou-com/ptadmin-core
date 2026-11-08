<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

use Composer\Semver\Semver;
use PTAdmin\Core\Tasks\TaskManager;

final class OfficialUpdateInstaller
{
    public function __construct(
        private readonly OfficialUpdateService $updates,
        private readonly InstalledVersions $installed,
        private readonly OfficialUpdatePolicy $policy,
        private readonly TaskManager $tasks,
    ) {
    }

    public function submit(string $component, string $version, int $actor, ?string $requestId = null, bool $databaseBackupConfirmed = false): array
    {
        if ($component === 'core' && !$databaseBackupConfirmed) {
            throw new UpdateException('请确认已备份数据库并阅读目标版本恢复说明。', 'backup_required');
        }
        $release = $this->resolve($component, $version);
        return $this->tasks->create($component === 'core' ? TaskManager::CORE_UPDATE : TaskManager::ADMIN_FRONTEND_UPDATE,
            ['component' => $component, 'version' => $version, 'name' => ($component === 'core' ? 'Core ' : 'Admin Shell ').$version,
                'official' => true, 'database_backup_confirmed' => $databaseBackupConfirmed, 'actor_id' => $actor, 'git_commit' => $release['git_commit'] ?? null], $requestId);
    }

    /** 排队后再执行也必须重查撤回状态和当前环境。 */
    public function resolve(string $component, string $version): array
    {
        if (!in_array($component, ['core', 'admin-shell'], true) || !ReleaseVersion::valid($version) || !ReleaseVersion::stable($version)) {
            throw new UpdateException('请选择有效的 Core 或 Shell 稳定版本。', 'invalid_target');
        }
        $document = $this->updates->fetchCatalog();
        $versions = $this->installed->read();
        $blockers = $this->policy->blockers($component, $versions);
        if ($blockers !== []) {
            throw new UpdateException(implode(' ', $blockers), 'update_unavailable');
        }
        if (ReleaseVersion::compare($version, $versions[$component]['version']) <= 0) {
            throw new UpdateException('目标版本必须高于当前已安装版本。', 'invalid_target');
        }
        $matches = array_values(array_filter($document['results'], static fn (array $release): bool => $release['component'] === $component && $release['version'] === $version));
        if (count($matches) !== 1) {
            throw new UpdateException('目标版本未发布或已撤回，请重新检查版本。', 'release_unavailable');
        }
        $release = $matches[0];
        $issues = $this->updates->compatibility($release, $versions);
        if ($component === 'admin-shell') {
            $current = array_filter($document['results'], static fn (array $entry): bool => $entry['component'] === 'admin-shell'
                && $entry['version'] === $versions['admin-shell']['version'] && $entry['artifact_sha256'] === $versions['admin-shell']['artifact_sha256']);
            if ($current === []) {
                $issues[] = '当前 Shell 的版本和摘要未在官方目录登记，请由维护者核对。';
            }
        } else {
            if (($release['composer_package'] ?? null) !== 'ptadmin/core' || ltrim($release['composer_version'] ?? '', 'v') !== $version
                || !is_string($release['git_commit'] ?? null) || !preg_match('/^[a-f0-9]{40}$/D', $release['git_commit'])) {
                $issues[] = 'Core 发行缺少准确的 Composer 包版本和提交。';
            }
            if (!$versions['admin-shell']['known'] || ($versions['admin-shell']['integrity'] ?? '') !== 'verified') {
                $issues[] = '当前 Shell 清单缺失或文件未通过校验，请先修复后再更新 Core。';
            }
            $manifestPath = public_path(config('ptadmin.web_prefix').'/manifest.json');
            if (is_file($manifestPath)) {
                $manifest = UpdateFiles::read($manifestPath);
                if (!Semver::satisfies($version, $manifest['backend_constraint'] ?? '0.0.0')) {
                    $issues[] = '当前 Shell 不兼容目标 Core，请先部署兼容 Shell。';
                }
            }
            foreach (glob(config('ptadmin.addon.path', base_path('addons')).'/*/manifest.json') ?: [] as $path) {
                $addon = UpdateFiles::read($path);
                $constraint = $addon['compatibility']['ptadmin/core'] ?? null;
                if (is_string($constraint) && !Semver::satisfies($version, $constraint)) {
                    $issues[] = '插件 '.($addon['code'] ?? basename(dirname($path))).' 不兼容目标 Core，请先处理插件版本。';
                }
            }
        }
        if ($issues !== []) {
            throw new UpdateException(implode(' ', $issues), 'incompatible_release');
        }
        return $release;
    }
}
