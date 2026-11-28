<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

use Illuminate\Filesystem\Filesystem;

/** 只从已校验公开目录取得同版本完整包，为标准 Composer 安装记录首次更新依据。 */
final class ComposerBaselineResolver
{
    public function prepare(array $document): void
    {
        try {
            $this->resolve($document);
        } catch (\Throwable $error) {
            throw $error instanceof UpdateException ? $error : new UpdateException('标准依赖核验失败：'.$error->getMessage(), 'baseline_unverified');
        }
    }

    private function resolve(array $document): void
    {
        $root = base_path();
        $delivery = config('ptadmin-updates.delivery');
        if (in_array($delivery, ['git', 'ci'], true) || ($delivery !== 'manual' && file_exists($root.'/.git'))) {
            return;
        }
        if (!is_file($root.'/composer.lock') || !is_file($root.'/vendor/composer/installed.json')) {
            return;
        }
        $inspection = (new DependencyBaseline())->inspect(null, true);
        if ($inspection['status'] === 'standard') {
            return;
        }
        $lock = UpdateFiles::read($root.'/composer.lock');
        $core = array_column($lock['packages'] ?? [], null, 'name')['ptadmin/core'] ?? null;
        if ($core === null) {
            return;
        }
        $matches = array_values(array_filter($document['results'], static fn (array $release): bool =>
            $release['component'] === 'application-template' && $release['status'] === 'published'
            && $release['version'] === ltrim($core['version'], 'v')
            && ($release['application']['target']['core_commit'] ?? null) === ($core['source']['reference'] ?? null)));
        if (count($matches) !== 1) {
            return;
        }
        $target = $matches[0]['application']['target'];
        // 损坏身份不能降级认领；Composer 正式换版须核验新版本完整包，保留原身份文件。
        $identitySha = null;
        if (is_file($root.'/'.PackageManifest::IDENTITY)) {
            $identity = UpdateFiles::read($root.'/'.PackageManifest::IDENTITY);
            PackageManifest::validate($identity);
            $identitySha = hash_file('sha256', $root.'/'.PackageManifest::IDENTITY);
            if ($identity['version'] === $target['version'] && $identity['build_id'] !== $target['build_id']) {
                return;
            }
        }
        $full = array_values(array_filter($matches[0]['application']['artifacts'], static fn (array $artifact): bool => $artifact['kind'] === 'full'));
        if (count($full) !== 1) {
            return;
        }
        $parent = dirname($root.'/'.DependencyBaseline::COMPOSER_IDENTITY);
        for ($path = $parent; $path !== $root; $path = dirname($path)) {
            if (is_link($path)) {
                throw new UpdateException('依赖核验目录不能为符号链接。', 'baseline_unverified');
            }
        }
        if (is_link($parent.'/composer-baseline.lock') || is_link($root.'/'.DependencyBaseline::COMPOSER_IDENTITY)) {
            throw new UpdateException('依赖核验记录不能为符号链接。', 'baseline_unverified');
        }
        UpdateFiles::directory($parent);
        $lockHandle = fopen($parent.'/composer-baseline.lock', 'c+');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if (is_resource($lockHandle)) {
                fclose($lockHandle);
            }
            throw new UpdateException('正在核验标准依赖，请稍后重新检查。', 'check_running');
        }
        $directory = $parent.'/baseline-'.bin2hex(random_bytes(8));
        try {
            if (is_file($root.'/'.DependencyBaseline::COMPOSER_IDENTITY)) {
                $previous = UpdateFiles::read($root.'/'.DependencyBaseline::COMPOSER_IDENTITY);
                PackageManifest::validate($previous['target']);
                if ($previous['target']['version'] === $target['version']) {
                    // 同版本核验后的文件变化不能通过再次检查静默认领。
                    return;
                }
            }
            $free = disk_free_space($parent);
            if ($free === false || $free < $full[0]['size'] + 2 * $target['managed_bytes'] + 67108864) {
                throw new UpdateException('没有足够空间核验当前版本的官方依赖。', 'insufficient_space');
            }
            UpdateFiles::directory($directory);
            $archive = (new OfficialShellArchive())->download($full[0], $directory, static function (): void {});
            $packages = new ApplicationPackage();
            $manifest = $packages->extract($archive, $directory.'/bundle', config('ptadmin-updates.max_uncompressed_bytes'));
            $summary = array_diff_key($manifest['target'], array_flip(['files', 'baseline']));
            if ($manifest['kind'] !== 'full' || PackageManifest::digest($summary) !== PackageManifest::digest($target)) {
                throw new UpdateException('当前版本完整包与公开发行记录不一致。', 'invalid_archive');
            }
            $packages->prepare($directory.'/bundle', $directory.'/verified');
            $target = $manifest['target'];
            $snapshot = PackageManifest::verifyComposerInstallation($root, $target);
            if ($identitySha !== (is_file($root.'/'.PackageManifest::IDENTITY) ? hash_file('sha256', $root.'/'.PackageManifest::IDENTITY) : null)) {
                throw new UpdateException('核验期间官方构建身份发生变化，请重新检查。', 'baseline_unverified');
            }
            UpdateFiles::write($root.'/'.DependencyBaseline::COMPOSER_IDENTITY, ['target' => $target, 'official_identity_sha256' => $identitySha,
                'files' => $snapshot['files'], 'development_dependencies' => $snapshot['development_dependencies'],
                'installed_sha256' => hash_file('sha256', $root.'/vendor/composer/installed.json')]);
        } finally {
            (new Filesystem())->deleteDirectory($directory);
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}
