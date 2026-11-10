<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 保留旧授权用于追溯，但不再把创始人专属入口作为可授权、可编辑的导航资源。
        DB::table('admin_resources')->where('resource_key', 'cloud.market')->whereNull('deleted_at')->update([
            'status' => false,
            'is_menu' => false,
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // 数据退役不逆转，避免回滚时重新开放可转授的云市场入口。
    }
};
