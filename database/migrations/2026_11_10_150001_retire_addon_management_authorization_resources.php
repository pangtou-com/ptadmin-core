<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 固定插件管理功能退出资源目录，历史授权保留用于追溯。
        DB::table('admin_resources')->whereIn('resource_key', [
            'addon.manage', 'addon.download', 'addon.install', 'addon.update',
            'addon.uninstall', 'addon.frontend', 'addon.enable', 'addon.disable',
        ])->whereNull('deleted_at')->update([
            'status' => false, 'is_menu' => false, 'deleted_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // 不重新开放可转授的插件生命周期入口。
    }
};
