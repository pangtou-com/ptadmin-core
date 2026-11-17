<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PTAdmin\Admin\Navigation\ExternalNavigationPermission;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(static function (): void {
            $menus = DB::table('admin_menus')->where('type', 'external')->where('entry_permission', '')->whereNull('deleted_at')->get();
            $permissions = DB::table('admin_resources')->whereIn('resource_key', $menus->pluck('resource_key'))->get()->keyBy('resource_key');
            $newPermissions = [];
            $updatedMenus = [];
            foreach ($menus as $menu) {
                $meta = json_decode($menu->meta_json ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                $definition = ExternalNavigationPermission::definition((array) $menu, $meta);
                $existing = $permissions->get($menu->resource_key);
                ExternalNavigationPermission::assertOwnership($definition, $existing);
                if ($existing === null) {
                    $newPermissions[] = $definition + ['created_at' => now(), 'updated_at' => now()];
                }
                $meta['permission_keys'] = array_values(array_unique([...($meta['permission_keys'] ?? []), $menu->resource_key]));
                $updatedMenus[] = array_replace((array) $menu, [
                    'meta_json' => json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
            }
            if ($newPermissions !== []) {
                DB::table('admin_resources')->insert($newPermissions);
            }
            if ($updatedMenus !== []) {
                DB::table('admin_menus')->upsert($updatedMenus, ['resource_key'], ['meta_json', 'updated_at']);
            }
        });
        if (Schema::hasColumn('admin_menus', 'visibility')) {
            Schema::table('admin_menus', static function (Blueprint $table): void {
                $table->dropColumn('visibility');
            });
        }
    }

    public function down(): void
    {
        Schema::table('admin_menus', static function (Blueprint $table): void {
            $table->string('visibility', 30)->default('permission')->comment('可见规则：permission 或 authenticated');
        });
        // 回滚结构不删除已经分配的外链权限和授权，也不恢复公开可见模式。
    }
};
