<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Lifecycle;

use Illuminate\Database\ConnectionInterface;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Frontend\FrontendPublisher;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Addon\Package\PackageExtractor;
use PTAdmin\Addon\Resource\ResourceSynchronizerInterface;
use PTAdmin\Addon\Runtime\RuntimeLifecycleValidator;
use PTAdmin\Schema\Runtime\RuntimeCacheStore;
use RuntimeException;

/**
 * 编排插件安装、升级、卸载和前端重新发布。
 *
 * 文件系统切换无法加入数据库事务，因此安装器先保留旧插件目录和生命周期快照，再同步资源与前端产物；
 * 任一步骤失败时执行补偿回滚。卸载只移除插件代码和公开前端文件，业务数据与配置值始终保留。
 */
final class Installer
{
    public function __construct(
        private ManifestVerifier $verifier,
        private PackageExtractor $extractor,
        private LifecycleStore $lifecycle,
        private FrontendPublisher $frontend,
        private string $addonsPath,
        private string $stagingPath,
        private bool $requireSignature = true,
        private ?ResourceSynchronizerInterface $resourceSynchronizer = null,
        private ?ConnectionInterface $database = null,
        private ?OperationLock $locks = null,
        private ?RuntimeCacheStore $runtimeCache = null,
        private ?RuntimeLifecycleValidator $runtimeValidator = null,
    ) {
        $this->addonsPath = rtrim($addonsPath, '/');
        $this->stagingPath = rtrim($stagingPath, '/');
    }

    /**
     * 安装或升级本地插件包，依次完成 artifact 校验、私有解包、manifest 验证、目录切换和资源激活。
     *
     * @param string $source
     * @param string $webPrefix
     * @param bool $update true 表示目标插件必须已安装，false 表示不允许覆盖现有插件
     * @param string|null $expectedCode 任务请求指定的插件编码，用于阻止包身份与操作目标不一致
     * @param string|null $expectedSha256 下载阶段记录的 artifact 摘要，用于安装前再次确认包未被替换
     * @return array<string, mixed>
     * @throws \JsonException
     * @throws \Throwable
     */
    public function install(
        string $source,
        string $webPrefix,
        bool $update = false,
        ?string $expectedCode = null,
        ?string $expectedSha256 = null,
    ): array {
        $this->verifyArtifactChecksum($source, $expectedSha256);
        $stage = $this->stagingPath.'/'.bin2hex(random_bytes(8));
        if (!is_dir($stage) && !mkdir($stage, 0700, true) && !is_dir($stage)) {
            throw new RuntimeException('Unable to create addon task staging directory.');
        }
        try {
            $package = $this->extractor->extract($source, $stage.'/package');
            $manifest = $this->verifier->verify($package, $this->requireSignature);
            $code = (string) $manifest['code'];
            if ($expectedCode !== null && strcasecmp($expectedCode, $code) !== 0) {
                throw new AddonException('The addon code does not match the requested operation.', 'addon_code_mismatch');
            }
            $lockHandle = $this->locks?->acquire($code);
            $this->runtimeCache?->clear();
            $target = $this->addonsPath.'/'.$code;
            $previousLifecycle = $this->lifecycle->read($code);
            $lifecycleCaptured = true;
            if (!$update && is_dir($target)) {
                throw new AddonException('The addon is already installed.', 'addon_already_installed');
            }
            if ($update && !is_dir($target)) {
                throw new AddonException('The addon is not installed.', 'addon_not_installed');
            }
            if (($previousLifecycle['state'] ?? null) === 'disposed') {
                $this->lifecycle->transition($code, 'discovered');
            }
            $this->lifecycle->transition($code, 'validating', ['version' => $manifest['version']]);
            $this->lifecycle->transition($code, 'loading');
            if (!is_dir($this->addonsPath) && !mkdir($this->addonsPath, 0755, true) && !is_dir($this->addonsPath)) {
                throw new RuntimeException('Unable to create addon directory.');
            }
            $replacement = $stage.'/replacement';
            rename($package, $replacement);
            $backup = $target.'.backup-'.bin2hex(random_bytes(5));
            // 先移走当前版本再原子切换 replacement，后续资源或前端失败时可恢复完整旧目录。
            if (is_dir($target) && !rename($target, $backup)) {
                throw new RuntimeException('Unable to stage installed addon.');
            }
            if (!rename($replacement, $target)) {
                if (is_dir($backup)) {
                    rename($backup, $target);
                }
                throw new RuntimeException('Unable to activate addon package.');
            }
            $frontendSource = $target.'/Frontend/dist';
            // 数据库资源在事务内同步；文件与前端产物由外层 catch 使用目录备份执行补偿回滚。
            $activate = function () use ($target, $code, $manifest, $frontendSource, $webPrefix, $update): array {
                $synced = $this->resourceSynchronizer?->sync($target, $code) ?? ['schemas' => 0, 'configs' => 0, 'admin_resources' => 0];
                $this->lifecycle->transition($code, 'ready', ['version' => $manifest['version'], 'manifest' => $manifest]);
                $this->lifecycle->transition($code, 'active');
                if (is_dir($frontendSource)) {
                    $this->frontend->publish($webPrefix, $code, $frontendSource);
                } elseif ($update) {
                    $this->frontend->remove($webPrefix, $code);
                }
                return $synced;
            };
            $synced = null !== $this->database ? $this->database->transaction($activate) : $activate();
            if (is_dir($backup)) {
                $this->deleteDirectory($backup);
            }
            return ['code' => $code, 'version' => $manifest['version'], 'path' => $target, 'frontend' => is_dir($frontendSource), 'resources' => $synced];
        } catch (\Throwable $exception) {
            if (isset($target, $backup) && is_dir($backup)) {
                if (is_dir($target)) {
                    $this->deleteDirectory($target);
                }
                @rename($backup, $target);
            } elseif (isset($target, $replacement) && is_dir($target) && !is_dir($replacement)) {
                $this->deleteDirectory($target);
            }
            if (isset($code) && ($lifecycleCaptured ?? false)) {
                $this->lifecycle->restore($code, $previousLifecycle ?? null);
                $restoredFrontend = isset($target) ? $target.'/Frontend/dist' : '';
                if (is_dir($restoredFrontend)) {
                    $this->frontend->publish($webPrefix, $code, $restoredFrontend);
                } else {
                    $this->frontend->remove($webPrefix, $code);
                }
            }
            throw $exception;
        } finally {
            if (is_dir($stage)) {
                $this->deleteDirectory($stage);
            }
            if (isset($lockHandle) && is_resource($lockHandle)) {
                $this->locks?->release($lockHandle);
            }
        }
    }

    /**
     * 卸载插件代码并停用资源；Schema 业务表、配置值和其他用户数据不会删除。
     *
     * @return array<string, mixed>
     */
    public function uninstall(string $code, string $webPrefix): array
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,63}$/', $code) !== 1) {
            throw new AddonException('The addon code is invalid.', 'invalid_addon_code');
        }
        $lockHandle = $this->locks?->acquire($code);
        try {
            $this->runtimeCache?->clear();
            $target = $this->addonsPath.'/'.$code;
            if (!is_dir($target)) {
                throw new AddonException('The addon is not installed.', 'addon_not_installed');
            }
            $previousLifecycle = $this->lifecycle->read($code);
            $backup = $target.'.uninstall-'.bin2hex(random_bytes(5));
            if (!rename($target, $backup)) {
                throw new RuntimeException('Unable to stage addon for uninstall.');
            }
            try {
                $dispose = function () use ($code, $webPrefix): void {
                    $this->lifecycle->transition($code, 'disposing');
                    $this->resourceSynchronizer?->deactivate($code);
                    $this->frontend->remove($webPrefix, $code);
                    $this->lifecycle->transition($code, 'disposed');
                    $this->runtimeValidator?->assertDeactivated($code);
                };
                null !== $this->database ? $this->database->transaction($dispose) : $dispose();
                $this->deleteDirectory($backup);
            } catch (\Throwable $exception) {
                if (is_dir($backup)) {
                    @rename($backup, $target);
                }
                $this->lifecycle->restore($code, $previousLifecycle);
                $frontendSource = $target.'/Frontend/dist';
                if (is_dir($frontendSource)) {
                    $this->frontend->publish($webPrefix, $code, $frontendSource);
                }
                throw $exception;
            }
            return ['code' => $code, 'removed' => true, 'data_preserved' => true];
        } finally {
            if (isset($lockHandle) && is_resource($lockHandle)) {
                $this->locks?->release($lockHandle);
            }
        }
    }

    /**
     * 重新发布已安装插件的 Frontend/dist，不改变插件生命周期或后端资源状态。
     *
     * 此入口只校验 frontend.json 的结构、入口路径和可读性；完整包签名与文件清单由安装流程负责。
     *
     * @return array<string, mixed>
     */
    public function syncFrontend(string $code, string $webPrefix): array
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,63}$/', $code) !== 1) {
            throw new AddonException('The addon code is invalid.', 'invalid_addon_code');
        }
        $lockHandle = $this->locks?->acquire($code);
        try {
            $source = $this->addonsPath.'/'.$code.'/Frontend/dist';
            if (!is_dir($source)) {
                throw new AddonException('The addon frontend source is unavailable.', 'source_unavailable');
            }
            $this->validateFrontendSource($this->addonsPath.'/'.$code, $source);
            return $this->frontend->publish($webPrefix, $code, $source);
        } finally {
            if (isset($lockHandle) && is_resource($lockHandle)) {
                $this->locks?->release($lockHandle);
            }
        }
    }

    /** 确保 frontend.json 声明的 trusted/iframe 入口位于 dist 内且文件类型与 sandbox 匹配。 */
    private function validateFrontendSource(string $addonDirectory, string $source): void
    {
        $sourceRoot = realpath($source);
        if (!is_string($sourceRoot) || !is_dir($sourceRoot)) {
            throw new AddonException('The addon frontend source is unavailable.', 'source_unavailable');
        }

        $descriptorPath = $addonDirectory.'/Frontend/frontend.json';
        if (!is_file($descriptorPath)) {
            return;
        }
        try {
            $descriptor = json_decode((string) file_get_contents($descriptorPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AddonException('The addon frontend descriptor is invalid JSON.', 'frontend_descriptor_invalid');
        }
        $entries = is_array($descriptor) ? ($descriptor['entries'] ?? null) : null;
        if (!is_array($descriptor) || array_is_list($descriptor) || !is_array($entries) || !array_is_list($entries)) {
            throw new AddonException('The addon frontend descriptor entries are invalid.', 'frontend_entries_invalid');
        }
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new AddonException('The addon frontend entry is invalid.', 'frontend_entry_invalid');
            }
            $entryPath = (string) ($entry['entry'] ?? '');
            $sandbox = (string) ($entry['sandbox'] ?? '');
            $segments = explode('/', $entryPath);
            if ($entryPath === '' || str_starts_with($entryPath, '/') || str_contains($entryPath, '\\')
                || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $entryPath) !== 1
                || !in_array($sandbox, ['trusted', 'iframe'], true)) {
                throw new AddonException('The addon frontend entry path is invalid.', 'frontend_entry_path_invalid');
            }
            $extension = strtolower((string) pathinfo($entryPath, PATHINFO_EXTENSION));
            if (($sandbox === 'trusted' && !in_array($extension, ['js', 'mjs'], true))
                || ($sandbox === 'iframe' && $extension !== 'html')
            ) {
                throw new AddonException('The addon frontend entry type is invalid.', 'frontend_entry_type_invalid');
            }
            $publishedEntry = realpath($sourceRoot.'/'.$entryPath);
            if (!is_string($publishedEntry) || !str_starts_with($publishedEntry, $sourceRoot.DIRECTORY_SEPARATOR) || !is_file($publishedEntry) || !is_readable($publishedEntry)) {
                throw new AddonException('The addon frontend entry is unavailable.', 'frontend_entry_unavailable', ['entry' => $entryPath]);
            }
        }
    }

    /** 在解包前复核本地 artifact，避免下载完成到安装执行之间发生文件替换。 */
    private function verifyArtifactChecksum(string $source, ?string $expectedSha256): void
    {
        if (null === $expectedSha256 || '' === $expectedSha256) {
            return;
        }

        $path = realpath($source);
        if (!is_string($path) || !is_file($path)) {
            throw new AddonException(
                'The addon checksum can only verify a local package artifact.',
                'checksum_source_unavailable'
            );
        }

        $actualSha256 = hash_file('sha256', $path);
        if (!is_string($actualSha256) || !hash_equals(strtolower($expectedSha256), $actualSha256)) {
            throw new AddonException('The addon artifact checksum is invalid.', 'checksum_mismatch');
        }
    }

    private function deleteDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
