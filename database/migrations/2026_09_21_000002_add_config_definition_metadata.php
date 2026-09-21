<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('ptadmin_config_definitions', 'exposure')) {
            Schema::table('ptadmin_config_definitions', static function (Blueprint $table): void {
                $table->string('exposure', 16)
                    ->default('internal')
                    ->after('description')
                    ->comment('配置定义读取范围：内部或公开');
            });
        }

        if (!Schema::hasColumn('ptadmin_config_definitions', 'activation_mode')) {
            Schema::table('ptadmin_config_definitions', static function (Blueprint $table): void {
                $table->string('activation_mode', 24)
                    ->default('immediate')
                    ->after('exposure')
                    ->comment('配置值保存后的生效方式');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ptadmin_config_definitions', 'exposure')) {
            Schema::table('ptadmin_config_definitions', static function (Blueprint $table): void {
                $table->dropColumn(['exposure', 'activation_mode']);
            });
        }
    }
};
