<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('admin_dicts', static function (Blueprint $table): void {
            $table->id();
            $table->string('code', 190)->comment('字典全局稳定编码');
            $table->string('title', 150)->comment('字典显示名称');
            $table->text('description')->nullable()->comment('字典用途说明');
            $table->string('source', 20)->comment('字典来源：system、plugin 或 custom');
            $table->string('owner_code', 128)->default('')->comment('系统模块或插件所有者编码');
            $table->string('exposure', 20)->default('internal')->comment('读取范围：internal 或 public');
            $table->string('value_type', 20)->default('string')->comment('字典值类型：string 或 number');
            $table->string('extension_policy', 20)->default('closed')->comment('扩展策略：closed、append 或 editable');
            $table->unsignedInteger('version')->default(1)->comment('定义并发控制版本号');
            $table->boolean('status')->default(true)->comment('字典是否可用');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->unsignedInteger('deleted_at')->nullable();
            $table->unique('code', 'admin_dicts_code_unique');
            $table->index(['source', 'owner_code', 'status'], 'admin_dicts_owner_status_index');
        });

        Schema::create('admin_dict_items', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('dict_id')->comment('所属字典主键');
            $table->string('label', 255)->comment('条目显示名称');
            $table->string('value', 255)->comment('字典内唯一且创建后不可修改的选项值');
            $table->text('display_json')->nullable()->comment('标签类型和颜色等展示配置');
            $table->string('source', 20)->comment('条目来源：system、plugin 或 custom');
            $table->string('owner_code', 128)->default('')->comment('系统模块或插件所有者编码');
            $table->boolean('enabled')->default(true)->comment('条目是否允许用于新数据');
            $table->integer('sort')->default(0)->comment('条目显示排序值');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->unsignedInteger('deleted_at')->nullable();
            $table->unique(['dict_id', 'value'], 'admin_dict_items_dict_value_unique');
            $table->index(['dict_id', 'enabled', 'sort'], 'admin_dict_items_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_dict_items');
        Schema::dropIfExists('admin_dicts');
    }
};
