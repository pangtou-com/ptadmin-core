<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('admin_tenants', static function (Blueprint $table): void {
            $table->id();
            $table->string('code', 100)->unique()->comment('租户稳定编码');
            $table->string('name', 100)->comment('租户名称');
            $table->boolean('status')->default(true)->comment('租户状态');
            $table->json('settings_json')->nullable()->comment('租户扩展配置');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->unsignedInteger('deleted_at')->nullable();
            $table->index('status');
        });

        Schema::create('admin_organizations', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->comment('组织所属租户，空值表示全局组织');
            $table->unsignedBigInteger('parent_id')->default(0)->comment('父组织主键，零表示根组织');
            $table->string('code', 100)->comment('组织稳定编码');
            $table->string('name', 100)->comment('组织名称');
            $table->boolean('status')->default(true)->comment('组织状态');
            $table->unsignedInteger('sort')->default(0)->comment('组织排序值');
            $table->json('meta_json')->nullable()->comment('组织扩展元数据');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->unsignedInteger('deleted_at')->nullable();
            $table->unique(['tenant_id', 'code'], 'admin_organizations_tenant_code_unique');
            $table->index(['tenant_id', 'parent_id'], 'admin_organizations_tree_index');
            $table->index('status');
        });

        Schema::create('admin_departments', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->comment('部门所属租户，空值表示全局');
            $table->unsignedBigInteger('organization_id')->default(0)->comment('部门所属组织主键');
            $table->unsignedBigInteger('parent_id')->default(0)->comment('父部门主键，零表示根部门');
            $table->string('code', 100)->comment('部门稳定编码');
            $table->string('name', 100)->comment('部门名称');
            $table->boolean('status')->default(true)->comment('部门状态');
            $table->unsignedInteger('sort')->default(0)->comment('部门排序值');
            $table->json('meta_json')->nullable()->comment('部门扩展元数据');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->unsignedInteger('deleted_at')->nullable();
            $table->unique(
                ['tenant_id', 'organization_id', 'code'],
                'admin_departments_organization_code_unique'
            );
            $table->index(['organization_id', 'parent_id'], 'admin_departments_tree_index');
            $table->index('status');
        });

        Schema::create('admin_user_org_relations', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->comment('组织关系所属租户，空值表示全局');
            $table->unsignedBigInteger('user_id')->comment('后台管理员主键');
            $table->unsignedBigInteger('organization_id')->comment('组织主键');
            $table->unsignedBigInteger('department_id')->nullable()->comment('部门主键');
            $table->boolean('is_primary')->default(false)->comment('是否为管理员主组织归属');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->unique(
                ['tenant_id', 'user_id', 'organization_id', 'department_id'],
                'admin_user_org_relations_unique'
            );
            $table->index(['tenant_id', 'user_id'], 'admin_user_org_relations_admin_index');
            $table->index(
                ['organization_id', 'department_id'],
                'admin_user_org_relations_organization_index'
            );
        });

        Schema::create('admin_dashboard_widgets', static function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 20)->comment('布局主体类型，例如 admin 或 role');
            $table->unsignedBigInteger('subject_id')->comment('布局主体主键');
            $table->unsignedBigInteger('tenant_id')->nullable()->comment('布局所属租户，空值表示全局');
            $table->string('widget_code', 150)->comment('仪表盘组件稳定编码');
            $table->boolean('enabled')->default(true)->comment('组件是否启用');
            $table->integer('sort')->default(0)->comment('组件排序值');
            $table->text('layout_json')->nullable()->comment('组件布局参数');
            $table->text('config_json')->nullable()->comment('组件实例配置');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->unique(
                ['tenant_id', 'subject_type', 'subject_id', 'widget_code'],
                'admin_dashboard_widgets_subject_unique'
            );
            $table->index(['subject_type', 'subject_id'], 'admin_dashboard_widgets_subject_index');
            $table->index('widget_code');
        });

        Schema::create('assets', static function (Blueprint $table): void {
            $table->id();
            $table->string('title', 255)->default('')->comment('资源展示名称');
            $table->string('md5', 32)->default('')->comment('资源内容 MD5 摘要');
            $table->string('mime', 100)->default('')->comment('资源 MIME 类型');
            $table->string('suffix', 20)->default('')->comment('资源文件扩展名');
            $table->string('driver', 50)->default('local')->comment('资源存储驱动');
            $table->unsignedBigInteger('size')->default(0)->comment('资源字节大小');
            $table->string('path', 500)->default('')->comment('资源在存储驱动中的路径');
            $table->string('groups', 50)->default('default')->comment('资源业务分组');
            $table->unsignedInteger('quote')->default(0)->comment('资源引用计数');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->unsignedInteger('deleted_at')->nullable();
            $table->index(['driver', 'path']);
            $table->index(['groups', 'created_at']);
            $table->index('md5');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
        Schema::dropIfExists('admin_dashboard_widgets');
        Schema::dropIfExists('admin_user_org_relations');
        Schema::dropIfExists('admin_departments');
        Schema::dropIfExists('admin_organizations');
        Schema::dropIfExists('admin_tenants');
    }
};
