<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

use Composer\Semver\Semver;
use Illuminate\Support\Facades\Http;
use PTAdmin\Cloud\CloudProtocol;
use PTAdmin\Core\Install\InstallStateStore;

/** 官方版本消费服务：只检查与提供指引，不执行 Composer 或替换用户文件。 */
final class OfficialUpdateService
{
    public function __construct(
        private readonly UpdateStateStore $store,
        private readonly OfficialCatalogVerifier $verifier,
        private readonly InstalledVersions $installed,
        private readonly UpdateEnvironment $environment,
        private readonly InstallStateStore $installation,
    ) {
    }

    public function check(bool $scheduled = false): void
    {
        $this->assertInstalled();
        if (!config('ptadmin-updates.enabled')) {
            throw new UpdateException('官方版本联网检查已由部署配置关闭，仍可查看缓存和人工更新指引。', 'checks_disabled');
        }
        $this->store->locked(function () use ($scheduled): void {
            $state = $this->store->read();
            if ($scheduled && ($state['next_check_at'] ?? 0) > time()) {
                return;
            }
            // 手动检查也受冷却约束，防止重复点击及多个创始人同时请求上游。
            if (!$scheduled && ($state['last_attempt_at'] ?? 0) > time() - 60) {
                throw new UpdateException('刚刚已执行检查，请一分钟后再试；可查看当前结果。', 'check_cooldown');
            }
            $state['last_attempt_at'] = time();
            if ($scheduled) {
                $state['last_scheduled_at'] = time();
            }
            try {
                $response = Http::acceptJson()->timeout(20)->connectTimeout(5)
                    ->withOptions(['allow_redirects' => false, 'stream' => true])
                    ->get(CloudProtocol::SITE_URL.'/api/cloud/official/releases');
                if (!$response->successful()) {
                    throw new UpdateException('官方目录暂不可用，请稍后重试；服务器需允许访问 cloud.api.pangtou.com 的 HTTPS 服务。', 'network_failed');
                }
                $stream = $response->toPsrResponse()->getBody();
                $body = '';
                while (!$stream->eof() && strlen($body) <= 16 * 1024 * 1024) {
                    $chunk = $stream->read(65536);
                    if ($chunk === '') {
                        break;
                    }
                    $body .= $chunk;
                }
                $stream->close();
                if (strlen($body) > 16 * 1024 * 1024) {
                    throw new UpdateException('官方目录响应超过允许大小。', 'invalid_catalog');
                }
                $result = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($result) || ($result['code'] ?? null) !== 0 || !is_array($result['data'] ?? null)) {
                    throw new UpdateException('官方目录响应格式无效。', 'invalid_catalog');
                }
                $envelope = array_intersect_key($result['data'], array_flip(['document']));
                $document = $this->verifier->verify($envelope);
                if (isset($state['envelope'])) {
                    $previous = $this->verifier->verify($state['envelope'], true);
                    if (strtotime($document['generated_at']) < strtotime($previous['generated_at'])) {
                        throw new UpdateException('收到比本地缓存更旧的官方目录，已拒绝回退。', 'catalog_rollback');
                    }
                    $old = array_column($previous['advisories'], 'revision', 'identifier');
                    foreach ($document['advisories'] as $advisory) {
                        if (isset($old[$advisory['identifier']]) && $advisory['revision'] < $old[$advisory['identifier']]) {
                            throw new UpdateException('安全公告修订号回退，已拒绝替换已校验缓存。', 'catalog_rollback');
                        }
                    }
                }
                $state['envelope'] = $envelope;
                $state['last_success_at'] = time();
                $state['failure_count'] = 0;
                $state['error'] = null;
                $state['next_check_at'] = time() + 86400 + random_int(0, 1800);
            } catch (\Throwable $error) {
                $state['failure_count'] = min(8, ($state['failure_count'] ?? 0) + 1);
                $state['error'] = $error instanceof UpdateException ? ['code' => $error->reason, 'message' => $error->getMessage()]
                    : ['code' => 'network_failed', 'message' => '获取官方目录失败，请检查服务器网络、TLS 与时钟后重试；上次已校验缓存仍保留。'];
                $state['next_check_at'] = time() + min(86400, 900 * 2 ** ($state['failure_count'] - 1)) + random_int(0, 300);
            }
            $this->store->write($state);
        });
    }

    public function overview(int $receiver, bool $founder): array
    {
        $this->assertInstalled();
        $versions = $this->installed->read();
        $state = [];
        $document = null;
        $error = null;
        try {
            $state = $this->store->read();
            $error = $state['error'] ?? null;
            if (isset($state['envelope'])) {
                $document = $this->verifier->verify($state['envelope'], true);
            }
        } catch (UpdateException $exception) {
            $error = ['code' => $exception->reason, 'message' => $exception->getMessage()];
        }
        $expired = $document !== null && strtotime($document['expires_at']) <= time();
        if ($document !== null && !$versions['admin-shell']['custom']) {
            $matchingShell = array_filter($document['results'], static fn (array $release): bool => $release['component'] === 'admin-shell'
                && $release['version'] === $versions['admin-shell']['version']
                && ($release['artifact_sha256'] ?? null) === ($versions['admin-shell']['artifact_sha256'] ?? null));
            if ($matchingShell === []) {
                $versions['admin-shell']['integrity'] = 'official_identity_unconfirmed';
            }
        }
        $status = $error !== null ? 'failed' : ($document === null ? 'never' : ($expired ? 'expired' : 'current'));
        $components = [];
        foreach (OfficialCatalogVerifier::COMPONENTS as $component) {
            $releases = array_values(array_filter($document['results'] ?? [], static fn (array $release): bool => $release['component'] === $component && ReleaseVersion::stable($release['version'])));
            usort($releases, static fn (array $a, array $b): int => ReleaseVersion::compare($b['version'], $a['version']));
            $current = $versions[$component];
            $latest = $releases[0] ?? null;
            $recommended = null;
            foreach ($releases as $release) {
                if ($current['known'] && ReleaseVersion::compare($release['version'], $current['version']) > 0
                    && $this->compatibility($release, $versions) === []) {
                    $recommended = $release;
                    break;
                }
            }
            $comparison = !$current['known'] ? 'unknown' : ($document === null ? 'unchecked' : ($latest === null ? 'unlisted'
                : (ReleaseVersion::compare($latest['version'], $current['version']) > 0 ? ($recommended ? 'update_available' : 'incompatible') : 'no_newer_release')));
            if ($component === 'admin-shell' && ($current['custom'] || ($current['integrity'] ?? 'unknown') !== 'verified')) {
                $comparison = $current['custom'] ? 'custom' : 'unknown';
                $recommended = null;
            }
            $components[] = ['component' => $component, 'current' => $current, 'comparison' => $comparison,
                'latest' => $latest, 'recommended' => $recommended,
                'blockers' => $latest ? $this->compatibility($latest, $versions) : [],
                'release_url' => CloudProtocol::AUTHORIZATION_URL.'/releases'.($latest ? '/'.$component.'/'.rawurlencode($latest['version']) : '')];
        }
        $advisories = [];
        foreach ($document['advisories'] ?? [] as $advisory) {
            $current = $versions[$advisory['component']];
            $version = $current['version'];
            $uncertain = !$current['known'];
            if ($advisory['component'] === 'admin-shell') {
                $uncertain = $uncertain || $current['custom'] || ($current['integrity'] ?? 'unknown') !== 'verified';
                $version = $current['custom'] ? $current['upstream_version'] : $version;
            }
            // 自定义源码即便声明了新基线也需要维护者核对，不能仅据版本号清除安全风险。
            if ($uncertain || ($version !== null && $this->satisfies($version, $advisory['affected_versions']))) {
                $ack = $state['acknowledged'][(string) $receiver][$advisory['identifier']] ?? null;
                $advisories[] = [...$advisory, 'match' => $uncertain ? 'review_required' : 'affected',
                    'acknowledged' => $ack === $advisory['revision'],
                    'fix_available' => $advisory['fixed_version'] !== '' && count(array_filter($document['results'],
                        fn (array $release): bool => $release['component'] === $advisory['component'] && $release['version'] === $advisory['fixed_version'] && $this->compatibility($release, $versions) === [])) > 0];
            }
        }
        $environment = $this->environment->inspect();
        $core = $components[0]['recommended'];
        return ['check' => ['status' => $status, 'error' => $error, 'last_success_at' => $state['last_success_at'] ?? null,
                'last_attempt_at' => $state['last_attempt_at'] ?? null, 'last_scheduled_at' => $state['last_scheduled_at'] ?? null,
                'expires_at' => $document['expires_at'] ?? null, 'cached' => $document !== null, 'expired' => $expired,
                'enabled' => (bool) config('ptadmin-updates.enabled'), 'can_check' => $founder && (bool) config('ptadmin-updates.enabled')],
            'components' => $components, 'advisories' => $founder ? $advisories : [], 'host_version' => $versions['host_version'],
            'environment' => $founder ? $environment : null,
            'core_guidance' => $this->environment->guidance($environment, $core['version'] ?? null, $founder),
            'shell_guidance' => '官方 Shell 的下载用于维护者验证与部署；Git/CI 交付请更新仓库产物，自定义 Shell 请合并源码重建。保留 modules/ 与 plugins/ 并验证模块兼容性。本阶段不提供在线替换按钮。',
            'template_guidance' => '基础壳版本是创建来源。已有项目应比较配置、启动和依赖变化后选择性合并，不重新 create-project 或覆盖 app、routes、.env。',
        ];
    }

    public function acknowledge(int $receiver, string $identifier, int $revision): void
    {
        $this->assertInstalled();
        $this->store->locked(function () use ($receiver, $identifier, $revision): void {
            $state = $this->store->read();
            if (!isset($state['envelope'])) {
                throw new UpdateException('尚无已缓存安全公告，请先检查版本。', 'advisory_unavailable');
            }
            $document = $this->verifier->verify($state['envelope'], true);
            $matches = array_filter($document['advisories'], static fn (array $advisory): bool => $advisory['identifier'] === $identifier && $advisory['revision'] === $revision);
            if ($matches === []) {
                throw new UpdateException('公告修订已变化，请刷新后阅读。', 'revision_conflict');
            }
            $state['acknowledged'][(string) $receiver][$identifier] = $revision;
            $this->store->write($state);
        });
    }

    private function compatibility(array $release, array $versions): array
    {
        $reasons = [];
        foreach (['php' => 'PHP', 'laravel' => 'Laravel', 'core' => 'Core'] as $key => $label) {
            if (!isset($release['compatibility'][$key])) {
                continue;
            }
            $current = $key === 'core' ? $versions['core']['version'] : $versions[$key];
            if ($current === null || !$this->satisfies($current, $release['compatibility'][$key])) {
                $reasons[] = $label.' 需要 '.$release['compatibility'][$key].'，当前为 '.($current ?? '未知').'。';
            }
        }
        foreach (['api_protocol' => 'API', 'schema_protocol' => 'Schema'] as $key => $label) {
            if (isset($release['compatibility'][$key]) && $release['compatibility'][$key] !== $versions[$key]) {
                $reasons[] = $label.' 协议需要 '.$release['compatibility'][$key].'，当前为 '.$versions[$key].'。';
            }
        }
        return $reasons;
    }

    private function satisfies(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (\UnexpectedValueException) {
            return false;
        }
    }

    private function assertInstalled(): void
    {
        if (!$this->installation->isInstalled()) {
            throw new UpdateException('请先完成应用安装，再检查官方版本。', 'not_installed');
        }
    }
}
