<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Factory as ProcessFactory;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Core\ApplicationIdentity;
use PTAdmin\Core\Theme\ThemeManifestValidator;
use ZipArchive;

/** 从源码副本构建发布包，签名、版本覆盖及前端清单处理均不写回源码。 */
final class ReleasePackageBuilder
{
    public function __construct(private readonly ApplicationIdentity $identity, private readonly Filesystem $files, private readonly ProcessFactory $processes = new ProcessFactory())
    {
    }

    /** 交互前只读取本地身份，不执行构建或签名。 @return array{code:string,version:string} */
    public function inspect(string $type, string $directory): array
    {
        if (!in_array($type, ['addon', 'template'], true) || !is_dir($directory) || is_link($directory)) {
            throw new CloudException('发布类型或源码目录无效：'.$directory, 'invalid_request');
        }
        $manifest = $this->readJson($directory.'/'.($type === 'addon' ? 'manifest.json' : 'theme.json'));
        if (!is_string($manifest['code'] ?? null) || preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $manifest['code']) !== 1
            || !is_string($manifest['version'] ?? null)) {
            throw new CloudException('源码清单编码或版本号无效。', 'invalid_request');
        }
        ReleaseVersion::validate($manifest['version']);
        return ['code' => $manifest['code'], 'version' => $manifest['version']];
    }

    /** 两个包在同一临时快照中生成；源码包摘要写入安装清单后再签名。
     * @param callable(array):mixed $consume
     * @param null|callable(string):void $log
     */
    public function withPackage(string $type, string $directory, ?string $version, callable $consume, bool $skipBuild = false, ?callable $log = null): mixed
    {
        $identity = $this->inspect($type, $directory);
        $version ??= $identity['version'];
        ReleaseVersion::validate($version);
        if (!class_exists(ZipArchive::class)) {
            throw new CloudException('版本发布需要 PHP zip 扩展。', 'package_unavailable');
        }
        $directory = realpath($directory);
        $work = storage_path('app/ptadmin/releases/.staging-'.bin2hex(random_bytes(12)));
        if (str_starts_with($work, $directory.DIRECTORY_SEPARATOR)) {
            throw new CloudException('发布源码目录不能包含临时发布目录。', 'invalid_request');
        }
        $snapshot = $work.'/snapshot';
        $root = $work.'/install';
        $sourceRoot = $work.'/source';
        $this->files->makeDirectory($snapshot, 0700, true);
        try {
            $configuration = ReleaseConfiguration::load($directory);
            $build = $configuration->build($directory, $type, $skipBuild);
            $excluded = [];
            $paths = $this->snapshot($directory, $snapshot, $excluded);
            $filename = $type === 'addon' ? 'manifest.json' : 'theme.json';
            $manifest = $this->readJson($snapshot.'/'.$filename);
            $keyId = $manifest['signature']['key_id'] ?? null;
            unset($manifest['signature'], $manifest['files'], $manifest['verified_files'], $manifest['release']);
            $manifest['version'] = $version;
            $this->writeJson($snapshot.'/'.$filename, $manifest);
            if ($manifest['code'] !== $identity['code']) {
                throw new CloudException('快照编码与发布来源不一致。', 'invalid_request');
            }
            $inputs = [];
            foreach ($paths as $path) {
                if ($this->sourceFile($path, $build)) {
                    $inputs[$path] = hash_file('sha256', $snapshot.'/'.$path);
                }
            }
            $sourcePaths = [];
            foreach ($paths as $path) {
                if ($this->sourceFile($path, $build) && $configuration->includes($path, 'source')) {
                    $sourcePaths[] = $path;
                }
            }
            // 清单和构建描述必须随源码交付，不能通过忽略规则生成不可重建的空源码包。
            foreach ($paths as $path) {
                if ($path === $filename || $path === ReleaseConfiguration::FILE
                    || str_starts_with($path, 'frontend/src/') || str_starts_with($path, 'src/')
                    || preg_match('#(^|/)(package\.json|(?:pnpm-lock\.yaml|yarn\.lock|package-lock\.json|bun\.lockb?)|frontend\.json|(?:vite|webpack|rollup)\.config\.[^/]+|tsconfig[^/]*\.json)$#', $path)) {
                    if (!in_array($path, $sourcePaths, true)) {
                        throw new CloudException('源码包不能忽略清单或构建配置：'.$path, 'invalid_request');
                    }
                }
            }
            foreach ($paths as $path) {
                if ($this->sourceFile($path, $build) && $this->installFile($path, $type)
                    && $configuration->includes($path, 'install') && !in_array($path, $sourcePaths, true)) {
                    throw new CloudException('源码包不能缺少安装包使用的源码或静态资源：'.$path, 'invalid_request');
                }
            }
            $this->copyPaths($snapshot, $sourceRoot, $sourcePaths);
            $this->writeJson($sourceRoot.'/ptadmin-source.json', ['type' => $type, 'code' => $identity['code'], 'version' => $version]);
            $sourcePaths[] = 'ptadmin-source.json';
            $sourceArchive = $work.'/'.$identity['code'].'-'.$version.'-source.zip';
            $this->archive($sourceRoot, $sourcePaths, $sourceArchive, 16 * 1024 * 1024);
            if ($build !== null && !$skipBuild) {
                $this->build($directory, $snapshot, $build, $log);
                foreach ($inputs as $path => $hash) {
                    if (!is_file($snapshot.'/'.$path) || hash_file('sha256', $snapshot.'/'.$path) !== $hash) {
                        throw new CloudException('构建修改了源码输入，双包无法保持一致：'.$path, 'invalid_request');
                    }
                }
            } elseif ($skipBuild) {
                if ($log !== null) {
                    $log('已显式跳过构建，校验现有产物。');
                }
            }
            $installPaths = [];
            foreach ($this->paths($snapshot) as $path) {
                if ($this->installFile($path, $type) && $configuration->includes($path, 'install')) {
                    $installPaths[] = $path;
                }
            }
            if (!in_array($filename, $installPaths, true)) {
                throw new CloudException('安装包不能忽略商品清单：'.$filename, 'invalid_request');
            }
            if (is_file($snapshot.'/frontend/frontend.json') && !in_array('frontend/frontend.json', $installPaths, true)) {
                throw new CloudException('安装包不能忽略 frontend/frontend.json。', 'invalid_request');
            }
            // 构建目录中的资源作为整体交付，避免忽略动态分块或样式后仅入口检查通过。
            $output = $build['output'] ?? ($type === 'addon' ? 'frontend/dist' : 'assets');
            foreach ($this->paths($snapshot) as $path) {
                if (str_starts_with($path, $output.'/') && $this->installFile($path, $type) && !in_array($path, $installPaths, true)) {
                    throw new CloudException('安装包不能忽略运行产物：'.$path, 'invalid_request');
                }
            }
            $this->copyPaths($snapshot, $root, $installPaths);
            $manifest['release'] = ['source_sha256' => hash_file('sha256', $sourceArchive)];
            $this->writeJson($root.'/'.$filename, $manifest);
            if ($type === 'addon') {
                if ($keyId !== null) {
                    $manifest['signature'] = ['key_id' => $keyId];
                }
                $this->prepareFrontend($root);
                $this->sign($root, $manifest);
            } else {
                (new ThemeManifestValidator())->validateDirectory($root, $identity['code'], $version, false, false);
            }
            $archive = $work.'/'.$identity['code'].'-'.$version.'.zip';
            $this->archive($root, $installPaths, $archive, 4 * 1024 * 1024);
            return $consume(['code' => $identity['code'], 'version' => $version, 'path' => $archive,
                'bytes' => filesize($archive), 'files' => count($installPaths), 'paths' => $installPaths,
                'excluded' => $excluded, 'source' => ['path' => $sourceArchive, 'bytes' => filesize($sourceArchive),
                    'files' => count($sourcePaths), 'paths' => $sourcePaths]]);
        } finally {
            $this->files->deleteDirectory($work);
        }
    }

    /** 在临时副本执行构建，只借用本地已安装依赖，既有源码与 dist 不被覆盖。 */
    private function build(string $original, string $snapshot, array $build, ?callable $log): void
    {
        $directory = $build['directory'];
        if (!is_dir($snapshot.'/'.$directory)) {
            throw new CloudException('构建工作目录不存在：'.$directory, 'invalid_request');
        }
        $links = [];
        try {
            foreach (array_unique(['.', $directory]) as $relative) {
                $dependencies = $original.'/'.$relative.'/node_modules';
                if (is_dir($dependencies)) {
                    $link = $snapshot.'/'.$relative.'/node_modules';
                    if (!symlink(realpath($dependencies), $link)) {
                        throw new CloudException('无法接入本地构建依赖：'.$relative, 'package_unavailable');
                    }
                    $links[] = $link;
                }
            }
            $this->files->deleteDirectory($snapshot.'/'.$build['output']);
            if ($log !== null) {
                $log('构建前端：'.implode(' ', $build['command']).'（'.$directory.'）');
            }
            $result = $this->processes->newPendingProcess()->path($snapshot.'/'.$directory)->timeout(600)->run($build['command'],
                $log === null ? null : static fn (string $type, string $output) => $log($output));
            if (!$result->successful()) {
                throw new CloudException('前端构建失败，不会上传旧产物：'.$result->errorOutput().$result->output(), 'package_unavailable');
            }
            if (!is_dir($snapshot.'/'.$build['output'])) {
                throw new CloudException('构建成功但未生成声明的产物目录：'.$build['output'], 'package_unavailable');
            }
        } finally {
            foreach ($links as $link) {
                unlink($link);
            }
        }
    }

    /** @return list<string> */
    private function snapshot(string $source, string $target, array &$excluded, string $prefix = ''): array
    {
        $paths = [];
        foreach (new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS) as $entry) {
            $relative = $prefix.$entry->getFilename();
            if ($this->excluded($relative)) {
                $excluded[] = $relative;
                continue;
            }
            if ($entry->isLink() || !ReleaseConfiguration::safePath($relative)) {
                throw new CloudException('发布目录包含符号链接或不安全路径：'.$relative, 'invalid_request');
            }
            if ($entry->isDir()) {
                $this->files->makeDirectory($target.'/'.$entry->getFilename(), 0700, true);
                array_push($paths, ...$this->snapshot($entry->getPathname(), $target.'/'.$entry->getFilename(), $excluded, $relative.'/'));
            } elseif ($entry->isFile() && $entry->isReadable()) {
                if ($entry->getSize() > 16 * 1024 * 1024) {
                    throw new CloudException('发布文件超出限制：'.$relative, 'invalid_request');
                }
                $this->files->copy($entry->getPathname(), $target.'/'.$entry->getFilename());
                $paths[] = $relative;
            } else {
                throw new CloudException('发布文件不可读取：'.$relative, 'package_unavailable');
            }
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    private function excluded(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if (in_array(strtolower($segment), ['.git', '.svn', '.idea', '.vscode', 'node_modules', 'coverage', '.cache', '.pnpm-store'], true)
                || preg_match('/^\.env(?:\.|$)/i', $segment) || in_array(strtolower($segment), ['.npmrc', '.yarnrc.yml', 'auth.json'], true)) {
                return true;
            }
        }
        return preg_match('/\.(?:zip|log|pem|key|p12|pfx)$/i', $path) === 1
            || in_array(explode('/', $path)[0], ['vendor', 'storage'], true);
    }

    private function sourceFile(string $path, ?array $build): bool
    {
        return !str_starts_with($path, 'frontend/dist/') && ($build === null || !str_starts_with($path, $build['output'].'/'))
            && $path !== 'ptadmin-source.json';
    }

    private function installFile(string $path, string $type): bool
    {
        if ($this->excluded($path) || str_ends_with($path, '.map') || in_array($path, [ReleaseConfiguration::FILE, 'ptadmin-source.json'], true)
            || preg_match('#(^|/)(tests?|__tests__)(/|$)#', $path)
            || preg_match('#(^|/)(package\.json|package-lock\.json|pnpm-lock\.yaml|yarn\.lock|bun\.lockb?|tsconfig[^/]*\.json|(?:vite|webpack|rollup)\.config\.[^/]+)$#', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if (str_starts_with($segment, '.') && $segment !== '.vite') {
                return false;
            }
        }
        if (str_starts_with($path, 'frontend/')) {
            return $type === 'addon' && ($path === 'frontend/frontend.json' || str_starts_with($path, 'frontend/dist/'));
        }
        return $type !== 'template' || !str_starts_with($path, 'src/');
    }

    /** @return list<string> */
    private function paths(string $root): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);
            if ($file->isLink() || !ReleaseConfiguration::safePath($path)) {
                throw new CloudException('构建结果包含不安全路径：'.$path, 'invalid_request');
            }
            if ($file->isDir()) {
                continue;
            }
            $paths[] = $path;
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    private function copyPaths(string $source, string $target, array $paths): void
    {
        $this->files->ensureDirectoryExists($target, 0700);
        foreach ($paths as $path) {
            $this->files->ensureDirectoryExists(dirname($target.'/'.$path), 0700);
            if (!$this->files->copy($source.'/'.$path, $target.'/'.$path)) {
                throw new CloudException('发布文件复制失败：'.$path, 'package_unavailable');
            }
        }
    }

    private function archive(string $root, array $paths, string $target, int $maxBytes): void
    {
        if (count($paths) > 2000 || count(array_unique(array_map('strtolower', $paths))) !== count($paths)) {
            throw new CloudException('发布文件超出限制或存在大小写冲突。', 'invalid_request');
        }
        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new CloudException('无法创建发布包。', 'package_unavailable');
        }
        try {
            $bytes = 0;
            foreach ($paths as $path) {
                $size = filesize($root.'/'.$path);
                $bytes += $size;
                if ($size > 16 * 1024 * 1024 || $bytes > 64 * 1024 * 1024) {
                    throw new CloudException('发布文件超出限制：单文件 16 MiB、总计 64 MiB。', 'invalid_request');
                }
                if (!$zip->addFile($root.'/'.$path, $path)) {
                    throw new CloudException('无法写入发布文件：'.$path, 'package_unavailable');
                }
            }
        } finally {
            $closed = $zip->close();
        }
        if (!$closed || filesize($target) > $maxBytes) {
            throw new CloudException('发布包生成失败或超出限制：'.($maxBytes / 1024 / 1024).' MiB。', 'invalid_request');
        }
    }

    private function prepareFrontend(string $root): void
    {
        $path = $root.'/frontend/frontend.json';
        if (!is_file($path)) {
            return;
        }
        $frontend = $this->readJson($path);
        unset($frontend['development']);
        if (!is_array($frontend['entries'] ?? null) || !array_is_list($frontend['entries']) || $frontend['entries'] === []) {
            throw new CloudException('插件 frontend.json 缺少有效 entries。', 'invalid_request');
        }
        foreach ($frontend['entries'] as $entry) {
            $entryPath = is_array($entry) ? ($entry['entry'] ?? null) : null;
            $sandbox = is_array($entry) ? ($entry['sandbox'] ?? null) : null;
            if (!in_array($sandbox, ['trusted', 'iframe'], true)) {
                throw new CloudException('插件前端入口必须声明 trusted 或 iframe sandbox。', 'invalid_request');
            }
            if (!is_string($entryPath) || !in_array(strtolower(pathinfo($entryPath, PATHINFO_EXTENSION)), $sandbox === 'trusted' ? ['js', 'mjs'] : ['html'], true) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/D', $entryPath) !== 1
                || array_intersect(['', '.', '..'], explode('/', $entryPath)) !== [] || !is_file($root.'/frontend/dist/'.$entryPath)) {
                throw new CloudException('插件前端入口产物缺失或路径无效，请先构建 frontend。', 'invalid_request');
            }
        }
        $this->writeJson($path, $frontend);
    }

    private function sign(string $root, array $manifest): void
    {
        $identity = $this->identity->load();
        $keyId = $manifest['signature']['key_id'] ?? $identity['application_instance_id'];
        if (!is_string($keyId) || $keyId === '') {
            throw new CloudException('插件签名 key_id 必须为非空字符串。', 'invalid_request');
        }
        $manifest['signature'] = ['algorithm' => 'RSA-SHA256', 'key_id' => $keyId, 'public_key' => $identity['public_key']];
        unset($manifest['verified_files'], $manifest['files']);
        $this->writeJson($root.'/manifest.json', $manifest);
        $verifier = new ManifestVerifier();
        $verified = $verifier->verify($root, false, false);
        if (!openssl_sign($verifier->signingPayload($manifest, $verified['verified_files']), $signature, $identity['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new CloudException('插件发布包签名失败：'.$manifest['code'], 'package_unavailable');
        }
        $manifest['signature']['value'] = base64_encode($signature);
        $this->writeJson($root.'/manifest.json', $manifest);
        $verifier->verify($root, true, false);
    }

    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            throw new CloudException('发布所需清单不存在：'.basename($path), 'invalid_request');
        }
        $value = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) {
            throw new CloudException('发布清单必须是 JSON 对象：'.basename($path), 'invalid_request');
        }
        return $value;
    }

    private function writeJson(string $path, array $value): void
    {
        if ($this->files->put($path, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
            throw new CloudException('发布清单写入失败：'.basename($path), 'package_unavailable');
        }
    }
}
