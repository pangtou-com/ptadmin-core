<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Meta\Service;

use PTAdmin\Schema\Contract\SchemaError;
use PTAdmin\Schema\Contract\VersionInfo;
use PTAdmin\Schema\Meta\Models\Resource;
use PTAdmin\Schema\Meta\Models\ResourceVersion;

/**
 * 集中解析资源与版本元数据，避免资源管理和发布流程各自维护一套查询及错误结构。
 */
final class ResourceLocator
{
    /** 按稳定资源键查询资源，不存在时返回协议错误。 */
    public function resource(string $resourceKey, string $stage = 'resource'): Resource
    {
        $resource = Resource::query()->where('resource_key', trim($resourceKey))->first();
        if (!$resource) {
            throw $this->error('draft_not_found', 'resource not found', $stage, ['resource_key' => $resourceKey]);
        }

        return $resource;
    }

    /** 查询资源的当前草稿版本。 */
    public function draft(Resource $resource, string $stage = 'draft'): ResourceVersion
    {
        $version = $resource->versions()->where('status', 'draft')->first();
        if (!$version) {
            throw $this->error('draft_not_found', 'draft version not found', $stage, ['resource_key' => $resource->resource_key]);
        }

        return $version;
    }

    /** 查询资源的活动版本。 */
    public function active(Resource $resource, string $stage = 'resource'): ResourceVersion
    {
        $version = $resource->versions()->where('status', 'active')->first();
        if (!$version) {
            throw $this->error('active_version_not_found', 'active version not found', $stage, ['resource_key' => $resource->resource_key]);
        }

        return $version;
    }

    /** 将版本模型转换为公共协议 DTO。 */
    public function versionInfo(ResourceVersion $version): VersionInfo
    {
        return new VersionInfo([
            'id' => (int) $version->id,
            'version' => (int) $version->version,
            'status' => $version->status,
            'checksum' => $version->checksum,
            'check_status' => $version->check_status,
            'checked_at' => $version->checked_at,
            'activated_at' => $version->activated_at,
            'created_at' => $version->created_at,
            'updated_at' => $version->updated_at,
        ]);
    }

    /** @param array<string, mixed> $details */
    private function error(string $code, string $message, string $stage, array $details): SchemaError
    {
        return new SchemaError($code, $message, $details, [], false, $stage);
    }
}
