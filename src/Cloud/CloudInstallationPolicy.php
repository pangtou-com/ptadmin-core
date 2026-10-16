<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

use Composer\Semver\Semver;
use Composer\Semver\Comparator;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Addon\Exception\AddonException;

/** 本地版本决定安装操作；平台授权不改变本地插件运行状态。 */
final class CloudInstallationPolicy
{
    public function __construct(private readonly ManifestVerifier $manifests)
    {
    }

    public function describe(string $code, string $type, string $version, string $coreRange): array
    {
        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $code) !== 1 || !in_array($type, ['addon', 'template'], true)) {
            throw new CloudException('平台返回的商品身份无效。', 'cloud_response_invalid');
        }
        $localVersion = null;
        $operation = 'install';
        $reason = '';
        if ($type === 'addon') {
            $path = rtrim((string) config('ptadmin.addon.path'), '/').'/'.$code;
            if (file_exists($path)) {
                try {
                    $localVersion = $this->manifests->verifyRuntime($path)['version'];
                    $operation = 'update';
                    if (Comparator::lessThan($version, $localVersion)) {
                        $reason = '该版本低于本地版本，可下载包但不能在线降级。';
                    }
                } catch (AddonException|\UnexpectedValueException $exception) {
                    $reason = '本地插件清单无法验证，请先检查本地插件。';
                }
            }
        } elseif (file_exists(rtrim((string) config('ptadmin.site_theme.directory', base_path('templates')), '/').'/'.$code.'@'.$version)) {
            $localVersion = $version;
            $reason = '该模板版本已安装，可下载包；安装不会覆盖已有模板文件。';
        }
        if ($coreRange !== '') {
            try {
                if (!Semver::satisfies((string) config('ptadmin.backend_version'), $coreRange)) {
                    $reason = '当前 Core 版本不满足该版本的兼容要求，可下载包。';
                }
            } catch (\UnexpectedValueException $exception) {
                $reason = '该版本的 Core 兼容声明无效。';
            }
        }
        return ['local_version' => $localVersion, 'install_operation' => $reason === '' ? $operation : null, 'install_reason' => $reason];
    }
}
