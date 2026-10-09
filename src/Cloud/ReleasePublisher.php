<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

use PTAdmin\Cloud\Contracts\CloudClientInterface;

/** 发布编排：定位源码、构建版本包、匹配授权商品并提交版本。 */
final class ReleasePublisher
{
    public function __construct(
        private readonly ReleasePackageBuilder $packages,
        private readonly CloudClientInterface $client,
        private readonly CloudSessionManager $sessions,
    ) {
    }

    /**
     * @param null|callable(list<string>):string $chooseSource 多个本地模板版本时由调用方选择来源目录。
     * @return array{type:string,directory:string,code:string,local_version:string,product:?array,latest_version:?string,dry_run:bool}
     */
    public function prepare(string $type, string $source, bool $dryRun, ?callable $chooseSource = null): array
    {
        if (!in_array($type, ['addon', 'template'], true)) {
            throw new CloudException('发布类型必须为 addon 或 template。', 'invalid_request');
        }
        $expectedCode = null;
        $expectedVersion = null;
        $directory = $source;
        if (!is_dir($source)) {
            $pattern = $type === 'addon' ? '/^[a-z][a-z0-9._-]{1,63}$/D' : '/^[a-z][a-z0-9._-]{1,63}(?:@\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)?$/D';
            if (preg_match($pattern, $source) !== 1) {
                throw new CloudException('请指定插件编码、模板编码或本地源码目录。', 'invalid_request');
            }
            $base = $type === 'addon' ? config('ptadmin.addon.path') : config('ptadmin.site_theme.directory');
            $directory = $type === 'template' && !str_contains($source, '@')
                ? $this->templateDirectory($base, $source, $chooseSource)
                : rtrim($base, '/').'/'.$source;
            $expectedCode = explode('@', $source)[0];
            if ($type === 'template') {
                $expectedVersion = explode('@', basename($directory), 2)[1];
            }
        }
        $manifest = $this->packages->inspect($type, $directory);
        if ($expectedCode !== null && $manifest['code'] !== $expectedCode) {
            throw new CloudException('源码清单编码与指定的插件或模板不一致。', 'invalid_request');
        }
        if ($expectedVersion !== null && $manifest['version'] !== $expectedVersion) {
            throw new CloudException('模板清单版本与来源目录不一致：'.basename($directory), 'invalid_request');
        }
        $product = $dryRun ? null : $this->product($this->sessions->token(), $type, $manifest['code']);
        if ($product !== null && (!array_key_exists('latest_version', $product)
            || ($product['latest_version'] !== null && !is_string($product['latest_version'])))) {
            throw new CloudException('平台未返回有效的最新版本信息，请更新平台后重试。', 'cloud_response_invalid');
        }
        return ['type' => $type, 'directory' => $directory, 'code' => $manifest['code'], 'local_version' => $manifest['version'],
            'product' => $product, 'latest_version' => $product['latest_version'] ?? null, 'dry_run' => $dryRun];
    }

    /** @param null|callable(list<string>):string $chooseSource */
    private function templateDirectory(string $base, string $code, ?callable $chooseSource): string
    {
        $directories = [];
        if (is_dir($base)) {
            foreach (new \FilesystemIterator($base, \FilesystemIterator::SKIP_DOTS) as $entry) {
                if ($entry->isDir() && preg_match('/^'.preg_quote($code, '/').'@\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $entry->getFilename()) === 1) {
                    $directories[] = $entry->getPathname();
                }
            }
        }
        sort($directories, SORT_STRING);
        if ($directories === []) {
            throw new CloudException('未找到本地模板：'.$code.'，请检查 '.$base.'/'.$code.'@版本号 目录。', 'invalid_request');
        }
        if (count($directories) === 1) {
            return $directories[0];
        }
        if ($chooseSource === null) {
            throw new CloudException('模板 '.$code.' 存在多个本地版本，请使用交互模式选择来源或指定具体源码目录。', 'invalid_request');
        }
        $selected = $chooseSource($directories);
        if (!in_array($selected, $directories, true)) {
            throw new CloudException('选择的模板来源不在候选目录中：'.$code, 'invalid_request');
        }
        return $selected;
    }

    /** @param array{type:string,directory:string,code:string,local_version:string,product:?array,latest_version:?string,dry_run:bool} $prepared */
    public function publish(array $prepared, string $version, string $changelog, bool $skipBuild = false, ?callable $log = null): array
    {
        ReleaseVersion::validate($version);
        return $this->packages->withPackage($prepared['type'], $prepared['directory'], $version, function (array $package) use ($prepared, $changelog): array {
            if ($package['code'] !== $prepared['code']) {
                throw new CloudException('源码清单编码与指定的插件或模板不一致。', 'invalid_request');
            }
            $result = ['code' => $package['code'], 'version' => $package['version'], 'bytes' => $package['bytes'], 'files' => $package['files'], 'dry_run' => $prepared['dry_run'], 'source_bytes' => $package['source']['bytes'],
                'source_files' => $package['source']['files'], 'paths' => $package['paths'], 'source_paths' => $package['source']['paths'], 'excluded' => $package['excluded']];
            if ($prepared['dry_run']) {
                return $result;
            }
            $token = $this->sessions->token();
            $product = $prepared['product'];
            $content = file_get_contents($package['path']);
            if ($content === false) {
                throw new CloudException('无法读取已生成的发布包。', 'package_unavailable');
            }
            $release = $this->client->submitRelease($token, $prepared['type'], ['product_id' => $product['id'],
                'name' => basename($package['path']), 'content_base64' => base64_encode($content), 'changelog' => $changelog,
                'source_package' => ['name' => basename($package['source']['path']),
                    'content_base64' => base64_encode((string) file_get_contents($package['source']['path']))]]);
            return $result + ['product_id' => $product['id'], 'release_id' => $release['id']];
        }, $skipBuild, $log);
    }

    private function product(string $token, string $type, string $code): array
    {
        $page = 1;
        do {
            $products = $this->client->products($token, ['page' => $page]);
            foreach ($products['results'] as $product) {
                if ($product['code'] === $code && $product['product_type'] === $type) {
                    return $product;
                }
            }
        } while ($page++ * 50 < $products['total']);
        throw new CloudException('当前会员没有可发布的对应商品：'.$type.'/'.$code.'。请确认商品已创建，且你是其所属开发者的负责人或成员。', 'forbidden');
    }
}
