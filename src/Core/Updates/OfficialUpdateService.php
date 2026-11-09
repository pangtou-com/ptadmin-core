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

    public function check(): void
    {
        $this->assertInstalled();
        if (!config('ptadmin-updates.enabled')) {
            throw new UpdateException('官方版本联网检查已由部署配置关闭，仍可查看缓存和人工更新指引。', 'checks_disabled');
        }
        $this->store->locked(function (): void {
            $state = $this->store->read();
            if (($state['last_attempt_at'] ?? 0) > time() - 60) {
                throw new UpdateException('刚刚已执行检查，请一分钟后再试；可查看当前结果。', 'check_cooldown');
            }
            $this->performCheck($state, false);
        });
    }

    /** 登录请求已完成；独立请求中的失败不传播到后台正常使用流程。 */
    public function checkOnLogin(string $sessionId, int $expiresAt): bool
    {
        if (!$this->installation->isInstalled() || !config('ptadmin-updates.enabled')) {
            return false;
        }
        try {
            return $this->store->locked(function () use ($sessionId, $expiresAt): bool {
                $state = $this->store->read();
                $now = now();
                $timestamp = $now->getTimestamp();
                $sessions = array_filter($state['login_check_sessions'] ?? [], static fn (int $expiry): bool => $expiry > $timestamp);
                $sessionHash = hash('sha256', $sessionId);
                if (isset($sessions[$sessionHash]) || $expiresAt <= $timestamp) {
                    return false;
                }
                // 即使本次配额已耗尽，同一登录会话也不在页面刷新时补发检查。
                $sessions[$sessionHash] = $expiresAt;
                $state['login_check_sessions'] = $sessions;
                $today = $now->toDateString();
                if (($state['login_check_day'] ?? '') !== $today) {
                    $state['login_check_day'] = $today;
                    $state['login_check_count'] = 0;
                }
                $due = $state['login_check_count'] < 2
                    && ($state['last_login_check_at'] ?? 0) <= $timestamp - 43200
                    && ($state['last_attempt_at'] ?? 0) <= $timestamp - 60;
                if ($due) {
                    $state['login_check_count']++;
                    $state['last_login_check_at'] = $timestamp;
                }
                // 先占用名额；超时、断网或进程退出也计入次数。
                $this->store->write($state);
                return $due && $this->performCheck($state, true);
            });
        } catch (\Throwable) {
            return false;
        }
    }

    private function performCheck(array $state, bool $automatic): bool
    {
        $state['last_attempt_at'] = now()->getTimestamp();
        $this->store->write($state);
        $succeeded = false;
        try {
            $state['envelope'] = ['document' => $this->fetchCatalog($automatic)];
            $state['last_success_at'] = now()->getTimestamp();
            $state['error'] = null;
            $succeeded = true;
        } catch (\Throwable $error) {
            if (!$automatic) {
                $state['error'] = $error instanceof UpdateException ? ['code' => $error->reason, 'message' => $error->getMessage()]
                    : ['code' => 'network_failed', 'message' => '获取官方目录失败，请检查服务器网络、TLS 与时钟后重试；上次已校验缓存仍保留。'];
            }
        }
        $this->store->write($state);
        return $succeeded;
    }

    /** 更新执行必须重新读取发布状态，不使用过期缓存或受检查冷却限制。 */
    public function fetchCatalog(bool $automatic = false): array
    {
        $this->assertInstalled();
        if (!config('ptadmin-updates.enabled')) {
            throw new UpdateException('官方联网检查已关闭。', 'checks_disabled');
        }
        $deadline = microtime(true) + ($automatic ? 4 : 20);
        $response = Http::acceptJson()->timeout($automatic ? 4 : 20)->connectTimeout($automatic ? 1 : 5)
            ->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => $automatic ? 1 : 5])
            ->get(CloudProtocol::SITE_URL.'/api/cloud/official/releases');
        if (!$response->successful()) {
            throw new UpdateException('官方目录暂不可用，请稍后重试；服务器需允许访问 cloud.api.pangtou.com 的 HTTPS 服务。', 'network_failed');
        }
        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        while (!$stream->eof() && strlen($body) <= 16 * 1024 * 1024) {
            if (microtime(true) >= $deadline) {
                $stream->close();
                throw new UpdateException('官方目录读取超时。', 'network_failed');
            }
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
        $state = $this->store->read();
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
        return $document;
    }

    /** @param array<string, bool> $canInstall 按组件裁决的发起更新权限。 */
    public function overview(int $receiver, bool $canManage, array $canInstall = [], bool $showUpdates = true, string $sessionId = ''): array
    {
        $this->assertInstalled();
        $versions = $this->installed->read();
        if (!$showUpdates) {
            $canManage = false;
            $canInstall = [];
        }
        $state = [];
        $document = null;
        $error = null;
        try {
            $state = $showUpdates ? $this->store->read() : [];
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
        $policy = app(OfficialUpdatePolicy::class);
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
            $updateReasons = !$showUpdates ? [] : ($component === 'application-template' ? ['基础壳来源不执行覆盖更新。']
                : $policy->blockers($component, $versions));
            if ($component === 'core' && $recommended !== null) {
                $updateReasons = [...$updateReasons, ...$policy->releaseBlockers($recommended, $versions)];
            }
            $components[] = ['component' => $component, 'current' => $current, 'comparison' => $comparison,
                'latest' => $latest, 'recommended' => $recommended,
                'blockers' => $latest ? $this->compatibility($latest, $versions) : [],
                'prerequisite' => null,
                'update' => ['permitted' => $canInstall[$component] ?? false, 'available' => ($canInstall[$component] ?? false) && $recommended !== null && !$expired && $error === null && $updateReasons === [], 'reasons' => $updateReasons],
                'release_url' => CloudProtocol::AUTHORIZATION_URL.'/releases'.($latest ? '/'.$component.'/'.rawurlencode($latest['version']) : '')];
        }
        $coreTarget = $components[0]['recommended'];
        if ($coreTarget !== null && $policy->releaseBlockers($coreTarget, $versions) === []) {
            $projected = $versions;
            $projected['core']['version'] = $coreTarget['version'];
            foreach ($components as &$entry) {
                if ($entry['component'] === 'admin-shell' && $entry['comparison'] !== 'custom' && $entry['comparison'] !== 'unknown'
                    && $entry['latest'] !== null && $entry['current']['known']
                    && ReleaseVersion::compare($entry['latest']['version'], $entry['current']['version']) > 0
                    && $this->compatibility($entry['latest'], $versions) !== []
                    && $this->compatibility($entry['latest'], $projected) === []) {
                    $entry['prerequisite'] = ['component' => 'core', 'version' => $coreTarget['version'], 'target_version' => $entry['latest']['version']];
                }
            }
            unset($entry);
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
        $environment = $showUpdates ? $this->environment->inspect() : null;
        $core = $components[0]['recommended'];
        return ['check' => ['visible' => $showUpdates, 'status' => $status, 'error' => $error, 'last_success_at' => $state['last_success_at'] ?? null,
                'last_attempt_at' => $state['last_attempt_at'] ?? null, 'last_login_check_at' => $state['last_login_check_at'] ?? null,
                'expires_at' => $document['expires_at'] ?? null, 'cached' => $document !== null, 'expired' => $expired,
                'enabled' => (bool) config('ptadmin-updates.enabled'), 'can_check' => $canManage && (bool) config('ptadmin-updates.enabled')],
            'reminders' => ['can_dismiss' => $showUpdates && $canManage,
                'session_dismissed' => $showUpdates && ($state['dismissed_sessions'][hash('sha256', $sessionId)] ?? 0) > time(),
                'ignored_versions' => $showUpdates ? ($state['ignored_versions'][(string) $receiver] ?? []) : []],
            'recovery_required' => $showUpdates && $policy->recoveryRequired(),
            'components' => $components, 'advisories' => $canManage ? $advisories : [], 'host_version' => $versions['host_version'],
            'environment' => $canManage ? $environment : null,
            'core_guidance' => $showUpdates ? $this->environment->guidance($environment, $core['version'] ?? null, $canManage)
                : ['mode' => 'maintainer_deployment', 'summary' => '', 'issues' => [], 'commands' => [], 'command_context' => '', 'steps' => []],
            'shell_guidance' => !$showUpdates ? '' : '标准官方 Shell 可从兼容版本发起更新，系统保留模块、插件和备份；Git/CI 或自定义 Shell 请合并源码后按原流程部署。',
            'template_guidance' => !$showUpdates ? '' : '基础壳版本是创建来源。已有项目应比较配置、启动和依赖变化后选择性合并，不重新 create-project 或覆盖 app、routes、.env。',
        ];
    }

    /** 只抑制普通版本提醒；安全公告及可安装目标保持独立。 */
    public function dismissReminder(int $receiver, string $sessionId, int $expiresAt, string $mode, ?string $component = null, ?string $version = null): void
    {
        $this->assertInstalled();
        $this->store->locked(function () use ($receiver, $sessionId, $expiresAt, $mode, $component, $version): void {
            $state = $this->store->read();
            if ($mode === 'session') {
                $sessions = array_filter($state['dismissed_sessions'] ?? [], static fn (int $expiry): bool => $expiry > time());
                $sessions[hash('sha256', $sessionId)] = $expiresAt;
                $state['dismissed_sessions'] = $sessions;
            } elseif ($mode === 'version' && in_array($component, ['core', 'admin-shell'], true)
                && ReleaseVersion::valid($version) && ReleaseVersion::stable($version)) {
                $document = $this->verifier->verify($state['envelope'] ?? [], true);
                $matches = array_filter($document['results'], static fn (array $release): bool => $release['component'] === $component && $release['version'] === $version);
                if ($matches === []) {
                    throw new UpdateException('目标版本已变化，请刷新版本信息后再设置提醒。', 'revision_conflict');
                }
                $ignored = $state['ignored_versions'][(string) $receiver][$component] ?? [];
                $state['ignored_versions'][(string) $receiver][$component] = array_values(array_unique([...$ignored, $version]));
            } else {
                throw new UpdateException('请选择有效的提醒范围和版本。', 'invalid_target');
            }
            $this->store->write($state);
        });
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

    public function compatibility(array $release, array $versions): array
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
