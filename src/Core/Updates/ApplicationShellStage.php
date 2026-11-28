<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

use Composer\Semver\Semver;
use PTAdmin\Core\Install\AdminFrontendManifest;

/** 联合更新只替换 Shell 清单文件，模块、插件及用户文件在 staging 中保留。 */
final class ApplicationShellStage
{
    public function prepare(string $directory, array $target): ?string
    {
        $path = public_path(config('ptadmin.web_prefix'));
        $current = app(InstalledVersions::class)->read();
        $old = (new AdminFrontendManifest($current['core']['version'], $current['api_protocol'], $current['schema_protocol']))->verify($path);
        if ($current['admin-shell']['custom']) {
            throw new UpdateException('定制后台界面请通过项目部署流程合并更新。', 'custom_shell');
        }
        if (ReleaseVersion::compare($old['frontend_version'], $target['shell']['version']) >= 0) {
            if (!Semver::satisfies($target['version'], $old['backend_constraint'])
                || $old['api_protocol'] !== $target['shell']['api_protocol']
                || $old['schema_protocol'] !== $target['shell']['schema_protocol']) {
                throw new UpdateException('当前同版或较新后台界面与目标协议不兼容，请选择兼容发行。', 'incompatible_release');
            }
            if ($old['frontend_version'] === $target['shell']['version'] && $old['artifact_sha256'] !== $target['shell']['artifact_sha256']) {
                throw new UpdateException('同版本 Shell 构建摘要不一致，请核对官方发行。', 'incompatible_release');
            }
            return null;
        }
        $source = $directory.'/project/vendor/ptadmin/core/resources/admin-frontend';
        $verifier = new AdminFrontendManifest($target['version'], $target['shell']['api_protocol'], $target['shell']['schema_protocol']);
        $next = $verifier->verify($source);
        if ($next['frontend_version'] !== $target['shell']['version'] || $next['artifact_sha256'] !== $target['shell']['artifact_sha256']) {
            throw new UpdateException('内置 Shell 与发行清单不一致。', 'invalid_archive');
        }
        if (stat($path)['dev'] !== stat($directory)['dev']) {
            throw new UpdateException('Shell 与备份目录须位于同一文件系统。', 'update_unavailable');
        }
        UpdateFiles::copy($path, $directory.'/shell');
        foreach ([...array_column($old['files'], 'path'), 'manifest.json'] as $relative) {
            if (!unlink($directory.'/shell/'.$relative)) {
                throw new UpdateException('无法准备旧 Shell 受管文件。', 'prepare_failed');
            }
        }
        foreach ([...array_column($next['files'], 'path'), 'manifest.json'] as $relative) {
            if (file_exists($directory.'/shell/'.$relative)) {
                throw new UpdateException('新 Shell 与用户文件冲突：'.$relative, 'user_file_conflict');
            }
            UpdateFiles::copy($source.'/'.$relative, $directory.'/shell/'.$relative);
            chmod($directory.'/shell/'.$relative, 0644);
        }
        $verifier->verify($directory.'/shell');
        return $path;
    }
}
