<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

use Composer\InstalledVersions as ComposerVersions;

/** 读取当前安装身份；vendor 内置 Shell 不代表 public 实际部署版本。 */
class InstalledVersions
{
    public function read(): array
    {
        $core = ComposerVersions::isInstalled('ptadmin/core') ? ComposerVersions::getPrettyVersion('ptadmin/core') : null;
        $core = is_string($core) ? preg_replace('/^v/', '', $core) : null;
        $corePath = ComposerVersions::isInstalled('ptadmin/core') ? ComposerVersions::getInstallPath('ptadmin/core') : null;
        $linkedCore = is_string($corePath) && is_link($corePath);
        $manifestPath = public_path(config('ptadmin.web_prefix', 'admin').'/manifest.json');
        $manifest = null;
        if (is_file($manifestPath) && is_readable($manifestPath) && filesize($manifestPath) <= 8 * 1024 * 1024) {
            $manifest = json_decode(file_get_contents($manifestPath), true);
        }
        $shell = is_array($manifest) && is_string($manifest['frontend_version'] ?? null) ? $manifest['frontend_version'] : null;
        $custom = (bool) config('ptadmin-updates.shell_custom');
        $integrity = 'unknown';
        if (ReleaseVersion::valid($core) && $shell !== null) {
            try {
                (new \PTAdmin\Core\Install\AdminFrontendManifest($core, (int) config('ptadmin.api_protocol'), (int) config('ptadmin.schema_protocol')))
                    ->verify(dirname($manifestPath));
                $integrity = 'verified';
            } catch (\RuntimeException) {
                $integrity = 'modified_or_invalid';
            }
        }
        $upstream = config('ptadmin-updates.shell_upstream_version');
        $template = config('ptadmin-updates.template_version');
        return [
            'core' => ['version' => $core, 'source' => $linkedCore ? 'development_path' : 'composer', 'known' => ReleaseVersion::valid($core) && !$linkedCore],
            'admin-shell' => ['version' => $shell, 'source' => 'deployed_manifest', 'known' => ReleaseVersion::valid($shell),
                'custom' => $custom, 'integrity' => $integrity, 'upstream_version' => ReleaseVersion::valid($upstream) ? $upstream : null,
                'artifact_sha256' => is_array($manifest) ? ($manifest['artifact_sha256'] ?? null) : null],
            'application-template' => ['version' => ReleaseVersion::valid($template) ? $template : null, 'source' => 'recorded_origin', 'known' => ReleaseVersion::valid($template)],
            'host_version' => config('ptadmin-updates.host_version') ?: null,
            'php' => PHP_VERSION, 'laravel' => app()->version(),
            'api_protocol' => (int) config('ptadmin.api_protocol'), 'schema_protocol' => (int) config('ptadmin.schema_protocol'),
        ];
    }
}
