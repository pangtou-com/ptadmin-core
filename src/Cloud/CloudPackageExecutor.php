<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

use PTAdmin\Addon\Lifecycle\Installer;
use PTAdmin\Cloud\Contracts\CloudClientInterface;
use PTAdmin\Cloud\Contracts\ProductDeliveryClientInterface;
use PTAdmin\Core\Tasks\TaskExecutorInterface;
use PTAdmin\Core\Tasks\TaskManager;
use PTAdmin\Core\Tasks\TaskStore;
use PTAdmin\Core\Theme\ThemeInstaller;

/** 云授权只在取得安装包时检查；取得后复用普通本地安装器。 */
final class CloudPackageExecutor implements TaskExecutorInterface
{
    public const TYPE = TaskManager::CLOUD_PACKAGE;

    public function __construct(private readonly CloudClientInterface $client, private readonly ProductDeliveryClientInterface $delivery,
        private readonly CloudSessionManager $sessions, private readonly TaskStore $store,
        private readonly Installer $addons, private readonly ThemeInstaller $themes, private readonly \PTAdmin\Addon\Manifest\ManifestVerifier $manifests)
    {
    }

    public function execute(array $task, TaskManager $tasks): array
    {
        $id = $task['task_id'];
        $payload = $task['payload'];
        $tasks->heartbeat($id, 'download', 5, '正在检查项目授权并下载版本包。');
        $token = $this->sessions->token();
        $selection = array_intersect_key($payload, array_flip(['license_id', 'release_id', 'package_kind']));
        $preview = $this->client->previewLicense($token, $selection);
        if ($preview['project_id'] !== $payload['project_id'] || $preview['code'] !== $payload['code']
            || $preview['version'] !== $payload['version'] || $preview['product_type'] !== $payload['product_type']) {
            throw new CloudException('项目或版本已变化，请重新发起下载。', 'invalid_request');
        }
        if ($payload['product_type'] === 'addon' && $payload['operation'] === 'update') {
            $installed = $this->manifests->verifyRuntime(rtrim((string) config('ptadmin.addon.path'), '/').'/'.$payload['code']);
            if (version_compare($payload['version'], $installed['version'], '<')) {
                throw new CloudException('在线更新不支持降级；可以下载旧版本包，但不会覆盖当前安装。', 'invalid_request');
            }
        }
        $artifact = $this->delivery->downloadPackage($token, $selection, $this->store->artifactsPath($id).'/package.zip');
        if ($payload['operation'] === 'download') {
            return ['download_ready' => true, 'sha256' => $artifact['sha256'], 'size' => $artifact['size']];
        }
        if ($payload['package_kind'] !== 'install') {
            throw new CloudException('源码包不能用于安装。', 'invalid_request');
        }
        $tasks->heartbeat($id, 'installing', 30, '正在校验并安装版本包。');
        if ($payload['product_type'] === 'template') {
            return $this->themes->install($artifact['artifact'], $payload['code'], $payload['version']);
        }
        return $this->addons->install($artifact['artifact'], (string) config('ptadmin.web_prefix', 'admin'),
            $payload['operation'] === 'update', $payload['code'], $artifact['sha256'],
            static function (string $stage, int $progress, string $message) use ($tasks, $id): void {
                $tasks->heartbeat($id, $stage, 30 + (int) round($progress * 0.7), $message);
            });
    }
}
