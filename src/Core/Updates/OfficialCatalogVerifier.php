<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

use Composer\Semver\VersionParser;
use PTAdmin\Cloud\CloudProtocol;

/** 校验固定 HTTPS 接口返回的官方目录结构、有效期与发行信息。 */
final class OfficialCatalogVerifier
{
    public const COMPONENTS = ['core', 'admin-shell', 'application-template'];

    public function verify(array $envelope, bool $allowExpired = false): array
    {
        $document = $envelope['document'] ?? null;
        if (!is_array($document) || ($document['protocol_version'] ?? null) !== 2) {
            throw new UpdateException('官方目录协议不受当前 Core 支持，请由维护者核对兼容版本。', 'unsupported_protocol');
        }
        $generated = is_string($document['generated_at'] ?? null) ? strtotime($document['generated_at']) : false;
        $expires = is_string($document['expires_at'] ?? null) ? strtotime($document['expires_at']) : false;
        if ($generated === false || $expires === false || $generated > time() + 300 || $expires <= $generated || $expires - $generated > 90000
            || (!$allowExpired && $expires <= time())) {
            throw new UpdateException('官方目录已过期或时间异常，请检查服务器时钟并重新检查。', 'expired_catalog');
        }
        if (!is_array($document['results'] ?? null) || !array_is_list($document['results']) || count($document['results']) > 10000
            || !is_array($document['advisories'] ?? null) || !array_is_list($document['advisories']) || count($document['advisories']) > 10000) {
            throw new UpdateException('官方目录缺少版本或安全公告列表。', 'invalid_catalog');
        }
        $identities = [];
        foreach ($document['results'] as $release) {
            $this->release($release);
            $identity = $release['component'].'@'.$release['version'];
            if (isset($identities[$identity])) {
                throw new UpdateException('官方目录包含重复版本身份。', 'invalid_catalog');
            }
            $identities[$identity] = true;
        }
        $identities = [];
        foreach ($document['advisories'] as $advisory) {
            if (!is_array($advisory) || !in_array($advisory['component'] ?? null, self::COMPONENTS, true)
                || !is_string($advisory['identifier'] ?? null) || !preg_match('/^[A-Z][A-Z0-9.-]{0,79}$/D', $advisory['identifier'])
                || isset($identities[$advisory['identifier']]) || !is_int($advisory['revision'] ?? null) || $advisory['revision'] < 0
                || !in_array($advisory['severity'] ?? null, ['low', 'moderate', 'high', 'critical'], true)
                || !is_string($advisory['summary'] ?? null) || !is_string($advisory['mitigation'] ?? null)
                || !is_string($advisory['fixed_version'] ?? null) || ($advisory['fixed_version'] !== '' && !ReleaseVersion::valid($advisory['fixed_version']))) {
                throw new UpdateException('官方安全公告字段无效。', 'invalid_catalog');
            }
            $this->constraint($advisory['affected_versions'] ?? null);
            $identities[$advisory['identifier']] = true;
        }
        return $document;
    }

    private function release(mixed $release): void
    {
        if (!is_array($release) || !in_array($release['component'] ?? null, self::COMPONENTS, true)
            || !ReleaseVersion::valid($release['version'] ?? null) || ($release['status'] ?? null) !== 'published'
            || !is_array($release['compatibility'] ?? null)) {
            throw new UpdateException('官方版本身份、状态或兼容声明无效。', 'invalid_catalog');
        }
        foreach (['changelog', 'breaking_changes', 'upgrade_steps', 'rollback_steps', 'github_url', 'gitee_url'] as $field) {
            if (!is_string($release[$field] ?? null)) {
                throw new UpdateException('官方版本缺少说明或来源字段：'.$field, 'invalid_catalog');
            }
        }
        foreach (['github_url' => 'github.com', 'gitee_url' => 'gitee.com'] as $field => $host) {
            if ($release[$field] !== '') {
                $this->url($release[$field], $host);
            }
        }
        $compatibility = $release['compatibility'];
        $required = $release['component'] === 'admin-shell' ? ['core'] : ['php', 'laravel'];
        if ($release['component'] === 'application-template') {
            $required[] = 'core';
        }
        foreach ($required as $field) {
            $this->constraint($compatibility[$field] ?? null);
        }
        if (isset($release['application'])) {
            $application = $release['application'];
            $target = $application['target'] ?? [];
            foreach (['build_id', 'baseline_sha256', 'tree_sha256'] as $field) {
                if (!is_string($target[$field] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $target[$field])) {
                    throw new UpdateException('官方构建身份无效。', 'invalid_catalog');
                }
            }
            if (($target['version'] ?? null) !== $release['version'] || !is_array($target['platform'] ?? null)
                || !is_array($target['host_files'] ?? null) || !is_array($target['shell'] ?? null)
                || ($target['profile'] ?? null) !== 'php-standard' || !is_int($target['managed_bytes'] ?? null) || $target['managed_bytes'] < 1
                || !is_array($application['artifacts'] ?? null) || !array_is_list($application['artifacts'])) {
                throw new UpdateException('官方应用构建描述无效。', 'invalid_catalog');
            }
            foreach ($target['platform'] as $name => $constraints) {
                if (!is_string($name) || !preg_match('/^(php|php-64bit|ext-[a-z0-9_-]+|lib-[a-z0-9_-]+)$/D', $name)
                    || !is_array($constraints) || !array_is_list($constraints) || $constraints === []) {
                    throw new UpdateException('官方平台要求无效。', 'invalid_catalog');
                }
                foreach ($constraints as $constraint) {
                    $this->constraint($constraint);
                }
            }
            foreach ($target['host_files'] as $path => $sha) {
                PackageManifest::path($path);
                if (!is_string($sha) || !preg_match('/^[a-f0-9]{64}$/D', $sha)) {
                    throw new UpdateException('官方宿主前置摘要无效。', 'invalid_catalog');
                }
            }
            $this->constraint($target['upgrade_from'] ?? null);
            $identities = [];
            foreach ($application['artifacts'] as $artifact) {
                if (!is_int($artifact['id'] ?? null) || $artifact['id'] < 1 || !in_array($artifact['kind'] ?? null, ['install', 'full', 'patch'], true)
                    || ($artifact['build_id'] ?? null) !== $target['build_id'] || !is_int($artifact['size'] ?? null) || $artifact['size'] < 1
                    || !preg_match('/^[a-f0-9]{64}$/D', $artifact['sha256'] ?? '')
                    || ($artifact['url'] ?? '') !== CloudProtocol::SITE_URL.'/api/cloud/official/artifacts/'.$artifact['id']) {
                    throw new UpdateException('官方应用产物地址或身份无效。', 'invalid_catalog');
                }
                $source = $artifact['source_build_id'] ?? null;
                if (!is_string($source) || ($artifact['kind'] === 'patch' ? !preg_match('/^[a-f0-9]{64}$/D', $source) : $source !== '')) {
                    throw new UpdateException('官方补丁来源无效。', 'invalid_catalog');
                }
                $identity = $artifact['kind'].'@'.$source;
                if (isset($identities[$identity])) {
                    throw new UpdateException('官方应用产物重复。', 'invalid_catalog');
                }
                $identities[$identity] = true;
            }
            if (!isset($identities['full@'], $identities['install@'])) {
                throw new UpdateException('官方应用缺少完整发行包。', 'invalid_catalog');
            }
        }
        if ($release['component'] === 'admin-shell') {
            foreach (['api_protocol', 'schema_protocol'] as $field) {
                if (!is_int($compatibility[$field] ?? null) || $compatibility[$field] < 1) {
                    throw new UpdateException('Shell 协议声明无效。', 'invalid_catalog');
                }
            }
            if (!is_string($release['artifact_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $release['artifact_sha256'])) {
                throw new UpdateException('Shell 资源摘要无效。', 'invalid_catalog');
            }
            foreach (['source', 'build'] as $kind) {
                $package = $release['packages'][$kind] ?? null;
                if (!is_array($package) || !is_string($package['url'] ?? null) || !is_string($package['sha256'] ?? null)
                    || !preg_match('/^[a-f0-9]{64}$/D', $package['sha256']) || !is_int($package['size'] ?? null) || $package['size'] < 1) {
                    throw new UpdateException('官方双包下载描述无效。', 'invalid_catalog');
                }
                $expected = CloudProtocol::SITE_URL.'/api/cloud/official/releases/admin-shell/'.rawurlencode($release['version']).'/packages/'.$kind;
                if ($package['url'] !== $expected) {
                    throw new UpdateException('官方双包地址不属于受信下载入口。', 'invalid_catalog');
                }
            }
        }
    }

    private function constraint(mixed $value): void
    {
        try {
            if (!is_string($value) || $value === '' || strlen($value) > 255) {
                throw new \UnexpectedValueException();
            }
            (new VersionParser())->parseConstraints($value);
        } catch (\UnexpectedValueException) {
            throw new UpdateException('官方版本兼容范围无效。', 'invalid_catalog');
        }
    }

    private function url(string $url, string $host): void
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== $host
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new UpdateException('官方来源链接必须使用指定平台的 HTTPS 地址。', 'invalid_catalog');
        }
    }
}
