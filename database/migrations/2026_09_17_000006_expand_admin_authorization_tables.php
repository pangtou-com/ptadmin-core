<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('admins', static function (Blueprint $table): void {
            $table->softDeletes();
        });

        Schema::table('admin_roles', static function (Blueprint $table): void {
            $table->string('description', 255)->nullable()->comment('角色用途说明');
            $table->unsignedBigInteger('tenant_id')->nullable()->comment('角色所属租户，空值表示全局角色');
            $table->string('scope_mode', 30)->nullable()->comment('角色默认数据范围模式');
            $table->json('scope_value_json')->nullable()->comment('角色默认数据范围参数');
            $table->softDeletes();
            $table->index(['tenant_id', 'status'], 'admin_roles_tenant_status_index');
        });

        Schema::table('admin_resources', static function (Blueprint $table): void {
            $table->string('module', 50)->default('admin')->comment('资源所属模块');
            $table->string('page_key', 100)->nullable()->comment('前端页面注册键');
            $table->string('addon_code', 100)->nullable()->comment('资源所属插件编码');
            $table->unsignedInteger('level')->default(0)->comment('资源树层级');
            $table->string('route', 150)->nullable()->comment('服务端路由标识');
            $table->string('icon', 100)->nullable()->comment('导航图标编码');
            $table->softDeletes();
            $table->index(['module', 'status'], 'admin_resources_module_status_index');
            $table->index('page_key', 'admin_resources_page_key_index');
            $table->index('addon_code', 'admin_resources_addon_code_index');
        });

        Schema::table('admin_user_roles', static function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable()->comment('角色关系所属租户，空值表示全局');
            $table->index(['tenant_id', 'admin_id'], 'admin_user_roles_tenant_admin_index');
        });

        Schema::table('admin_grants', static function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable()->comment('授权所属租户，空值表示全局');
            $table->string('scope_type', 30)->nullable()->comment('授权数据范围类型');
            $table->json('scope_value_json')->nullable()->comment('授权数据范围参数');
            $table->json('conditions_json')->nullable()->comment('授权附加判定条件');
            $table->index(
                ['tenant_id', 'subject_type', 'subject_id'],
                'admin_grants_tenant_subject_index'
            );
            $table->index('expires_at', 'admin_grants_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('admin_grants', static function (Blueprint $table): void {
            $table->dropIndex('admin_grants_tenant_subject_index');
            $table->dropIndex('admin_grants_expires_at_index');
            $table->dropColumn(['tenant_id', 'scope_type', 'scope_value_json', 'conditions_json']);
        });

        Schema::table('admin_user_roles', static function (Blueprint $table): void {
            $table->dropIndex('admin_user_roles_tenant_admin_index');
            $table->dropColumn('tenant_id');
        });

        Schema::table('admin_resources', static function (Blueprint $table): void {
            $table->dropIndex('admin_resources_module_status_index');
            $table->dropIndex('admin_resources_page_key_index');
            $table->dropIndex('admin_resources_addon_code_index');
            $table->dropColumn(['module', 'page_key', 'addon_code', 'level', 'route', 'icon', 'deleted_at']);
        });

        Schema::table('admin_roles', static function (Blueprint $table): void {
            $table->dropIndex('admin_roles_tenant_status_index');
            $table->dropColumn(['description', 'tenant_id', 'scope_mode', 'scope_value_json', 'deleted_at']);
        });

        Schema::table('admins', static function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
