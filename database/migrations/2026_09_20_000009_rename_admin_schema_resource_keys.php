<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /** @var array<string, array{resource:string,name:string,previous_name:string}> */
    private const RESOURCE_RENAMES = [
        'admin.management_users' => ['resource' => 'admin.admin_users', 'name' => 'admin_users', 'previous_name' => 'management_users'],
        'admin.management_roles' => ['resource' => 'admin.admin_roles', 'name' => 'admin_roles', 'previous_name' => 'management_roles'],
        'admin.management_menus' => ['resource' => 'admin.admin_resources', 'name' => 'admin_resources', 'previous_name' => 'management_menus'],
    ];

    public function up(): void
    {
        $this->rename(self::RESOURCE_RENAMES);
    }

    public function down(): void
    {
        $renames = [];
        foreach (self::RESOURCE_RENAMES as $previousResource => $definition) {
            $renames[$definition['resource']] = [
                'resource' => $previousResource,
                'name' => $definition['previous_name'],
                'previous_name' => $definition['name'],
            ];
        }

        $this->rename($renames);
    }

    /** @param array<string, array{resource:string,name:string,previous_name:string}> $renames */
    private function rename(array $renames): void
    {
        DB::transaction(function () use ($renames): void {
            foreach ($renames as $previousResource => $definition) {
                $nextResource = $definition['resource'];
                $this->renameUniqueResource('schema_resources', $previousResource, $nextResource, [
                    'name' => $definition['name'],
                ]);
                $this->renameUniqueResource('admin_resources', $previousResource, $nextResource);
                $this->replaceValue('admin_resources', 'parent_key', $previousResource, $nextResource);
                $this->replaceValue('schema_audit_events', 'resource_key', $previousResource, $nextResource);
                $this->replaceValue('admin_operation_records', 'resource_key', $previousResource, $nextResource);
            }

            $this->renameAdminResourceMetadata($renames);
        });
    }

    /** @param array<string, mixed> $values */
    private function renameUniqueResource(
        string $table,
        string $previousResource,
        string $nextResource,
        array $values = [],
    ): void {
        $query = DB::table($table);
        if (!$query->where('resource_key', $previousResource)->exists()) {
            return;
        }
        if (DB::table($table)->where('resource_key', $nextResource)->exists()) {
            throw new \RuntimeException(sprintf(
                '无法将资源编码 %s 重命名为 %s：数据表 %s 已存在目标编码。',
                $previousResource,
                $nextResource,
                $table,
            ));
        }

        DB::table($table)->where('resource_key', $previousResource)->update($values + [
            'resource_key' => $nextResource,
        ]);
    }

    private function replaceValue(string $table, string $column, string $previousValue, string $nextValue): void
    {
        DB::table($table)->where($column, $previousValue)->update([$column => $nextValue]);
    }

    /** @param array<string, array{resource:string,name:string,previous_name:string}> $renames */
    private function renameAdminResourceMetadata(array $renames): void
    {
        foreach (DB::table('admin_resources')->whereNotNull('meta_json')->get(['id', 'meta_json']) as $resource) {
            $metadata = json_decode((string) $resource->meta_json, true);
            if (!is_array($metadata)) {
                continue;
            }

            $renamed = $this->renameMetadataValues($metadata, $renames);
            if ($renamed !== $metadata) {
                DB::table('admin_resources')->where('id', $resource->id)->update([
                    'meta_json' => json_encode($renamed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $metadata
     * @param array<string, array{resource:string,name:string,previous_name:string}> $renames
     * @return array<array-key, mixed>
     */
    private function renameMetadataValues(array $metadata, array $renames): array
    {
        foreach ($metadata as $key => $value) {
            if (is_array($value)) {
                $metadata[$key] = $this->renameMetadataValues($value, $renames);
                continue;
            }
            if (is_string($value) && isset($renames[$value])) {
                $metadata[$key] = $renames[$value]['resource'];
            }
        }

        return $metadata;
    }
};
