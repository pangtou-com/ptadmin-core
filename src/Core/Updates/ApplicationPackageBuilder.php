<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

use RuntimeException;
use ZipArchive;

/** 在已完成生产依赖安装的隔离构建目录生成完整包及文件差异包。 */
final class ApplicationPackageBuilder
{
    public function build(string $root, string $output, string $version, string $adminCommit, string $upgradeFrom, ?string $previous = null): array
    {
        if (file_exists($output)) {
            throw new RuntimeException('发行输出目录已存在，拒绝覆盖。');
        }
        $composer = UpdateFiles::read($root.'/composer.json');
        $lock = UpdateFiles::read($root.'/composer.lock');
        $installed = UpdateFiles::read($root.'/vendor/composer/installed.json');
        if (($installed['dev'] ?? true) !== false || ($composer['config']['classmap-authoritative'] ?? false)
            || ($composer['autoload']['psr-4'] ?? null) !== ['App\\' => 'app/']) {
            throw new RuntimeException('构建需要标准 App PSR-4、生产 vendor 和非权威自动加载。');
        }
        foreach ($lock['packages'] as $package) {
            if (($package['type'] ?? '') === 'composer-plugin' || ($package['dist']['type'] ?? '') === 'path') {
                throw new RuntimeException('官方构建不允许 Composer 插件或路径依赖。');
            }
        }
        $packages = array_column($lock['packages'], null, 'name');
        $core = $packages['ptadmin/core'] ?? [];
        if (ltrim($core['version'] ?? '', 'v') !== $version) {
            throw new RuntimeException('Admin 与 Core 必须为同一准确版本。');
        }
        $shell = UpdateFiles::read($root.'/vendor/ptadmin/core/resources/admin-frontend/manifest.json');
        (new \PTAdmin\Core\Install\AdminFrontendManifest($version, $shell['api_protocol'], $shell['schema_protocol']))
            ->verify($root.'/vendor/ptadmin/core/resources/admin-frontend');
        $platform = PackageManifest::platform($composer, $lock);
        $host = [];
        foreach (['public/index.php', 'artisan', 'bootstrap/update-guard.php', 'bootstrap/app.php', 'config/auth.php'] as $path) {
            if (!is_file($root.'/'.$path)) {
                throw new RuntimeException('缺少标准宿主文件：'.$path);
            }
            $host[$path] = hash_file('sha256', $root.'/'.$path);
        }
        $files = PackageManifest::files($root);
        $baseline = ['composer' => PackageManifest::composer($composer), 'lock' => PackageManifest::lock($lock)];
        $target = ['protocol_version' => 2, 'version' => $version, 'profile' => 'php-standard',
            'admin_commit' => $adminCommit, 'core_commit' => $core['source']['reference'], 'upgrade_from' => $upgradeFrom,
            'baseline' => $baseline, 'baseline_sha256' => PackageManifest::digest($baseline),
            'files' => $files, 'managed_bytes' => array_sum(array_column($files, 'size')), 'tree_sha256' => PackageManifest::digest($files), 'host_files' => $host, 'platform' => $platform,
            'shell' => ['version' => $shell['frontend_version'], 'artifact_sha256' => $shell['artifact_sha256'],
                'backend_constraint' => $shell['backend_constraint'], 'api_protocol' => $shell['api_protocol'], 'schema_protocol' => $shell['schema_protocol']]];
        $target['build_id'] = PackageManifest::digest($target);
        PackageManifest::validate($target);
        UpdateFiles::directory($output);
        $full = ['protocol_version' => 2, 'kind' => 'full', 'target' => $target, 'source' => null, 'upsert' => $files, 'delete' => []];
        $artifacts = [$this->archive($root, $output.'/full.zip', $full)];
        if ($previous !== null) {
            $source = UpdateFiles::read($previous.'/'.PackageManifest::IDENTITY);
            PackageManifest::verify($previous, $source, false);
            if ($source['profile'] !== $target['profile'] || !\Composer\Semver\Semver::satisfies($source['version'], $upgradeFrom)) {
                throw new RuntimeException('指定补丁来源不满足升级范围。');
            }
            $upsert = array_filter($files, static fn ($file, $path) => ($source['files'][$path] ?? null) !== $file, ARRAY_FILTER_USE_BOTH);
            $patch = ['protocol_version' => 2, 'kind' => 'patch', 'target' => $target,
                'source' => array_intersect_key($source, array_flip(['build_id', 'baseline_sha256', 'tree_sha256'])),
                'upsert' => $upsert, 'delete' => array_values(array_diff(array_keys($source['files']), array_keys($files)))];
            $artifacts[] = $this->archive($root, $output.'/patch.zip', $patch);
        }
        UpdateFiles::write($root.'/'.PackageManifest::IDENTITY, $target);
        $inspection = (new DependencyBaseline())->inspect($root, true);
        if ($inspection['status'] !== 'standard') {
            throw new RuntimeException($inspection['reason']);
        }
        $zip = new ZipArchive();
        if ($zip->open($output.'/install.zip', ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('无法创建安装包。');
        }
        $installPaths = ['vendor', 'app', 'config', 'routes', 'resources', 'templates', 'lang', 'database', 'public', 'composer.json', 'composer.lock', 'artisan', '.env.example', 'bootstrap/app.php', 'bootstrap/update-guard.php', PackageManifest::IDENTITY];
        foreach ($installPaths as $path) {
            if (!file_exists($root.'/'.$path)) {
                continue;
            }
            foreach (PackageManifest::files($root, [$path]) as $file => $_) {
                if (str_contains($file, '/.env') || preg_match('#(?:^|/)(?:auth\.json|installed\.json|ptadmin\.json)$#', $file) && !str_starts_with($file, 'vendor/')) {
                    throw new RuntimeException('安装包包含运行凭据或安装状态：'.$file);
                }
                $zip->addFile($root.'/'.$file, $file);
            }
        }
        foreach (['addons', 'bootstrap/cache', 'storage/app', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $path) {
            $zip->addEmptyDir($path);
        }
        $zip->close();
        $artifacts[] = $this->description($output.'/install.zip', 'install', $target, null);
        $report = ['protocol_version' => 2, 'target' => $target, 'artifacts' => $artifacts];
        UpdateFiles::write($output.'/release.json', $report);
        return $report;
    }

    private function archive(string $root, string $path, array $manifest): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('无法创建升级包。');
        }
        $zip->addFromString('package.json', PackageManifest::canonical($manifest));
        foreach ($manifest['upsert'] as $file => $_) {
            $zip->addFile($root.'/'.$file, 'payload/'.$file);
        }
        $zip->close();
        return $this->description($path, $manifest['kind'], $manifest['target'], $manifest['source']);
    }

    private function description(string $path, string $kind, array $target, ?array $source): array
    {
        return ['kind' => $kind, 'name' => basename($path), 'size' => filesize($path), 'sha256' => hash_file('sha256', $path),
            'build_id' => $target['build_id'], 'source_build_id' => $source['build_id'] ?? '', 'profile' => $target['profile']];
    }
}
