<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Assets;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use PTAdmin\Admin\Models\Asset;

final class AssetService
{
    /**
     * @param array<string, mixed> $filters
     * @return array{total:int,results:list<array<string,mixed>>,page:int,limit:int,groups:list<array{code:string,name:string}>}
     */
    public function paginate(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $query = $this->boolean($filters['only_deleted'] ?? false)
            ? Asset::onlyTrashed()
            : ($this->boolean($filters['include_deleted'] ?? false) ? Asset::withTrashed() : Asset::query());

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(static function (Builder $query) use ($keyword): void {
                $query->where('title', 'like', '%'.$keyword.'%')
                    ->orWhere('original_title', 'like', '%'.$keyword.'%');
            });
        }
        $groupCode = trim((string) ($filters['group_code'] ?? ''));
        if ($groupCode !== '') {
            $query->where('group_code', $groupCode);
        }
        $mime = trim((string) ($filters['mime'] ?? ''));
        if ($mime !== '') {
            str_ends_with($mime, '/*')
                ? $query->where('mime', 'like', substr($mime, 0, -1).'%')
                : $query->where('mime', $mime);
        }

        $total = (clone $query)->count();
        $assets = $query->orderByDesc('id')->forPage($page, $limit)->get();

        return [
            'total' => $total,
            'results' => $assets->map(fn (Asset $asset): array => $this->serialize($asset))->values()->all(),
            'page' => $page,
            'limit' => $limit,
            'groups' => $this->groups(),
        ];
    }

    /** @return array<string, mixed> */
    public function find(int $id, bool $includeDeleted = false): array
    {
        $query = $includeDeleted ? Asset::withTrashed() : Asset::query();

        return $this->serialize($this->asset($query, $id));
    }

    /** @return array<string, mixed> */
    public function update(int $id, string $title): array
    {
        $asset = $this->asset(Asset::query(), $id);
        $asset->forceFill(['title' => $title])->save();

        return $this->serialize($asset->fresh());
    }

    public function archive(int $id): void
    {
        $this->asset(Asset::query(), $id)->delete();
    }

    public function restore(int $id): void
    {
        $asset = $this->asset(Asset::onlyTrashed(), $id);
        $asset->restore();
    }

    /** @return array<string, mixed> */
    public function serialize(Asset $asset): array
    {
        $metadata = $asset->metadata_json;

        $result = [
            'id' => (int) $asset->getKey(),
            'title' => (string) $asset->title,
            'original_title' => (string) $asset->original_title,
            'mime' => (string) $asset->mime,
            'suffix' => (string) $asset->suffix,
            'size' => (int) $asset->size,
            'url' => $this->url($asset),
            'group_code' => (string) $asset->group_code,
            'disk_code' => (string) $asset->disk_code,
            'bucket' => (string) $asset->bucket,
            'path' => (string) $asset->path,
            'ref_count' => (int) $asset->ref_count,
            'sha256' => (string) $asset->sha256,
            'created_at' => $this->timestamp($asset->getRawOriginal('created_at')),
            'updated_at' => $this->timestamp($asset->getRawOriginal('updated_at')),
            'deleted_at' => $asset->trashed() ? $this->timestamp($asset->getRawOriginal('deleted_at')) : null,
        ];
        if (is_array($metadata) && $metadata !== []) {
            $result['metadata'] = $metadata;
        }

        return $result;
    }

    private function asset(Builder $query, int $id): Asset
    {
        $asset = $query->find($id);
        if (!$asset instanceof Asset) {
            throw new AssetException('asset_not_found', '资源不存在。', 404);
        }

        return $asset;
    }

    /** @return list<array{code:string,name:string}> */
    private function groups(): array
    {
        $groups = Asset::withTrashed()
            ->where('group_code', '<>', '')
            ->distinct()
            ->orderBy('group_code')
            ->pluck('group_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();
        $groups = array_values(array_filter($groups, static fn (string $code): bool => $code !== 'default'));
        array_unshift($groups, 'default');

        return array_map(static fn (string $code): array => ['code' => $code, 'name' => $code], $groups);
    }

    private function url(Asset $asset): ?string
    {
        if ((string) $asset->path === '' || (string) $asset->disk_code === '') {
            return null;
        }

        $disk = Storage::disk((string) $asset->disk_code);
        if (!$disk instanceof FilesystemAdapter) {
            throw new \RuntimeException('资源存储磁盘不支持生成访问地址。');
        }

        return $disk->url((string) $asset->path);
    }

    private function timestamp(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }
        $timestamp = strtotime((string) $value);

        return $timestamp === false ? 0 : $timestamp;
    }

    private function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
