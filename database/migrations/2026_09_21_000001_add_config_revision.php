<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('ptadmin_config_values', 'revision')) {
            Schema::table('ptadmin_config_values', static function (Blueprint $table): void {
                $table->unsignedInteger('revision')->default(1)->after('checksum')->comment('配置值并发修改版本号');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ptadmin_config_values', 'revision')) {
            Schema::table('ptadmin_config_values', static function (Blueprint $table): void {
                $table->dropColumn('revision');
            });
        }
    }
};
