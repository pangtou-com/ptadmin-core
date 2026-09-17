<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('ptadmin_config_definitions', static function (Blueprint $table): void {
            $table->id();
            $table->string('group_code', 64)->comment('配置固定分组编码');
            $table->string('config_key', 191)->comment('配置稳定编码');
            $table->string('owner_type', 32)->comment('配置所有者类型');
            $table->string('owner_code', 128)->comment('配置所有者编码');
            $table->string('title', 150)->comment('配置展示名称');
            $table->text('description')->default('')->comment('配置用途说明');
            $table->json('schema_json')->comment('配置 Schema 定义');
            $table->string('checksum', 64)->comment('配置 Schema 内容摘要');
            $table->boolean('status')->default(true)->comment('配置定义是否启用');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['owner_type', 'owner_code', 'config_key'], 'ptadmin_config_definitions_owner_key_unique');
            $table->index(['group_code', 'status'], 'ptadmin_config_definitions_group_index');
        });

        Schema::create('ptadmin_config_values', static function (Blueprint $table): void {
            $table->id();
            $table->string('config_key', 191)->comment('配置稳定编码');
            $table->string('owner_type', 32)->comment('配置所有者类型');
            $table->string('owner_code', 128)->comment('配置所有者编码');
            $table->string('scope_type', 32)->default('global')->comment('配置值作用域类型');
            $table->string('scope_id', 128)->default('')->comment('配置值作用域标识');
            $table->json('value_json')->comment('配置对象值');
            $table->string('checksum', 64)->comment('配置值内容摘要');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(
                ['owner_type', 'owner_code', 'config_key', 'scope_type', 'scope_id'],
                'ptadmin_config_values_scope_unique'
            );
            $table->index(['owner_type', 'owner_code'], 'ptadmin_config_values_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ptadmin_config_values');
        Schema::dropIfExists('ptadmin_config_definitions');
    }
};
