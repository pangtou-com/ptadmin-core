<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('schema_resources', static function (Blueprint $table): void {
            $table->json('retired_fields_json')->nullable()->comment('停用字段原定义、停用版本和保留列结构');
            $table->boolean('publish_repair_required')->default(false)->comment('发布失败已改变数据库结构，需要显式修复');
        });
    }

    public function down(): void
    {
        Schema::table('schema_resources', static function (Blueprint $table): void {
            $table->dropColumn(['retired_fields_json', 'publish_repair_required']);
        });
    }
};
