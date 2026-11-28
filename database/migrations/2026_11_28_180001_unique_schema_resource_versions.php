<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 历史重复草稿可能各自包含修改，禁止自动丢弃其中一份。
        $duplicate = DB::table('schema_resource_versions')->select('resource_id', 'version')
            ->groupBy('resource_id', 'version')->havingRaw('COUNT(*) > 1')->first();
        if ($duplicate !== null) {
            throw new RuntimeException(sprintf('模型 %s 存在重复版本 %s，请核对并保留所需草稿后重新迁移。', $duplicate->resource_id, $duplicate->version));
        }
        Schema::table('schema_resource_versions', static function (Blueprint $table): void {
            $table->unique(['resource_id', 'version'], 'schema_resource_version_unique');
        });
    }

    public function down(): void
    {
        Schema::table('schema_resource_versions', static function (Blueprint $table): void {
            $table->dropUnique('schema_resource_version_unique');
        });
    }
};
