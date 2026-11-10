<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('admin_menus', function (Blueprint $table): void {
            $table->id();
            $table->string('resource_key', 191)->unique()->comment('导航稳定编码，用于匹配前端页面');
            $table->string('title', 150)->comment('导航展示名称');
            $table->string('type', 30)->comment('导航类型：group、page、schema、external');
            $table->string('parent_key', 191)->default('')->comment('父导航编码');
            $table->string('path', 255)->default('')->comment('后台页面相对路径');
            $table->string('entry_permission', 300)->default('')->comment('进入页面所需的业务资源及操作编码');
            $table->string('visibility', 30)->default('permission')->comment('可见规则：permission 或 authenticated');
            $table->string('module', 100)->default('admin')->comment('导航所属模块');
            $table->string('icon', 255)->nullable()->comment('导航图标');
            $table->json('meta_json')->nullable()->comment('来源、页面绑定及关联权限资源编码');
            $table->boolean('is_menu')->default(true)->comment('是否显示在导航中');
            $table->boolean('status')->default(true)->comment('导航是否启用，不改变业务权限状态');
            $table->unsignedInteger('sort')->default(0)->comment('同级导航排序');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['parent_key', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_menus');
    }
};
