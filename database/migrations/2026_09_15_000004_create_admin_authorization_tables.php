<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('admin_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 100)->unique()->comment('角色稳定编码');
            $table->string('name', 100)->comment('角色名称');
            $table->boolean('status')->default(true)->comment('角色状态');
            $table->boolean('is_system')->default(false)->comment('是否系统角色');
            $table->unsignedInteger('sort')->default(0)->comment('排序值');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        Schema::create('admin_resources', function (Blueprint $table): void {
            $table->id();
            $table->string('resource_key', 191)->unique()->comment('资源稳定编码');
            $table->string('title', 150)->comment('资源展示名称');
            $table->string('type', 30)->default('page')->comment('资源类型');
            $table->string('parent_key', 191)->default('')->comment('父资源编码');
            $table->string('path', 255)->default('')->comment('前端相对路由路径');
            $table->json('meta_json')->nullable()->comment('菜单和页面元数据');
            $table->boolean('is_menu')->default(false)->comment('是否显示为菜单');
            $table->boolean('status')->default(true)->comment('资源状态');
            $table->unsignedInteger('sort')->default(0)->comment('排序值');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['parent_key', 'sort']);
        });

        Schema::create('admin_user_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id')->comment('管理员主键');
            $table->unsignedBigInteger('role_id')->comment('角色主键');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique(['admin_id', 'role_id']);
        });

        Schema::create('admin_grants', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 30)->comment('授权主体类型');
            $table->string('subject_id', 100)->comment('授权主体标识');
            $table->unsignedBigInteger('resource_id')->comment('资源主键');
            $table->string('effect', 10)->default('allow')->comment('授权效果');
            $table->json('abilities_json')->comment('动作能力列表');
            $table->unsignedInteger('priority')->default(0)->comment('授权优先级');
            $table->timestamp('expires_at')->nullable()->comment('授权失效时间');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['resource_id', 'effect']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_grants');
        Schema::dropIfExists('admin_user_roles');
        Schema::dropIfExists('admin_resources');
        Schema::dropIfExists('admin_roles');
    }
};
