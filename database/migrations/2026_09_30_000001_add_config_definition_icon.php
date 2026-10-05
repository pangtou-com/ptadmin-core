<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('ptadmin_config_definitions', 'icon')) {
            Schema::table('ptadmin_config_definitions', static function (Blueprint $table): void {
                $table->string('icon', 32)
                    ->default('general')
                    ->after('description')
                    ->comment('设置中心配置分类图标：通用、安全、资源、通知或自定义');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ptadmin_config_definitions', 'icon')) {
            Schema::table('ptadmin_config_definitions', static function (Blueprint $table): void {
                $table->dropColumn('icon');
            });
        }
    }
};
