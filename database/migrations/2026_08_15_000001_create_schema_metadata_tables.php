<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('schema_resources', static function (Blueprint $table): void {
            $table->id();
            $table->string('namespace', 64)->default('')->comment('资源命名空间');
            $table->string('name', 128)->comment('资源名称');
            $table->string('resource_key', 191)->unique()->comment('资源稳定编码');
            $table->string('table_name', 128)->comment('运行时数据表名');
            $table->string('migration_owner', 32)->default('schema')->comment('迁移归属');
            $table->text('title')->comment('资源展示标题');
            $table->text('description')->default('')->comment('资源说明');
            $table->string('owner_type', 64)->default('')->comment('资源所有者类型');
            $table->string('owner_code', 128)->default('')->comment('资源所有者编码');
            $table->string('design_flow_id', 191)->default('')->comment('设计流程标识');
            $table->string('source_type', 64)->default('')->comment('定义来源类型');
            $table->unsignedBigInteger('active_version_id')->default(0)->comment('当前生效版本主键');
            $table->string('status', 32)->default('draft')->comment('资源状态');
            $table->boolean('allow_import')->default(false)->comment('是否允许导入');
            $table->boolean('allow_export')->default(false)->comment('是否允许导出');
            $table->boolean('allow_duplicate')->default(false)->comment('是否允许复制');
            $table->boolean('allow_print')->default(false)->comment('是否允许打印');
            $table->boolean('allow_recycle')->default(false)->comment('是否允许回收站');
            $table->boolean('allow_draft')->default(false)->comment('是否允许草稿');
            $table->string('draft_field', 128)->default('')->comment('草稿状态字段');
            $table->json('draft_value')->nullable()->comment('草稿状态值');
            $table->string('track_mode', 64)->default('')->comment('变更追踪模式');
            $table->string('created_by', 128)->default('')->comment('创建者标识');
            $table->string('updated_by', 128)->default('')->comment('更新者标识');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->softDeletes();
            $table->unique(['namespace', 'name']);
        });

        Schema::create('schema_resource_versions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('resource_id')->comment('资源主键');
            $table->unsignedInteger('version')->comment('版本号');
            $table->string('status', 32)->default('draft')->comment('版本状态');
            $table->json('schema_json')->comment('Schema 定义内容');
            $table->boolean('allow_draft')->default(false)->comment('是否允许草稿');
            $table->string('draft_field', 128)->default('')->comment('草稿状态字段');
            $table->json('draft_value')->nullable()->comment('草稿状态值');
            $table->string('checksum', 64)->comment('定义内容摘要');
            $table->json('change_summary_json')->nullable()->comment('变更摘要');
            $table->string('check_status', 32)->default('none')->comment('校验状态');
            $table->string('check_checksum', 64)->default('')->comment('校验摘要');
            $table->json('validation_result_json')->nullable()->comment('校验结果');
            $table->json('migration_plan_json')->nullable()->comment('迁移计划');
            $table->string('checked_by', 128)->default('')->comment('校验者标识');
            $table->timestamp('checked_at')->nullable()->comment('校验时间');
            $table->string('activated_by', 128)->default('')->comment('激活者标识');
            $table->timestamp('activated_at')->nullable()->comment('激活时间');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['resource_id', 'status']);
        });

        Schema::create('schema_field_projections', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('resource_id')->comment('资源主键');
            $table->unsignedBigInteger('version_id')->comment('版本主键');
            $table->string('name', 128)->comment('字段名称');
            $table->unsignedInteger('sort')->default(0)->comment('字段排序');
            $table->string('type', 64)->comment('字段类型');
            $table->text('label')->comment('字段展示标签');
            $table->string('column_name', 128)->default('')->comment('数据库列名');
            foreach (['persisted', 'multiple', 'required', 'readonly', 'disabled', 'listable', 'formable', 'searchable', 'sortable'] as $column) {
                $table->boolean($column)->default(false)->comment('字段 '.$column.' 属性');
            }
            $table->json('extensions')->nullable()->comment('字段扩展配置');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['resource_id', 'sort']);
            $table->index(['version_id', 'sort']);
        });

        Schema::create('schema_publish_tasks', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('resource_id')->comment('资源主键');
            $table->unsignedBigInteger('version_id')->comment('版本主键');
            $table->string('status', 32)->default('pending')->comment('任务状态');
            $table->string('phase', 32)->default('checking')->comment('任务阶段');
            $table->string('triggered_by', 128)->default('')->comment('触发者标识');
            $table->string('check_checksum', 64)->default('')->comment('校验摘要');
            $table->json('migration_result_json')->nullable()->comment('迁移结果');
            $table->json('projection_result_json')->nullable()->comment('投影结果');
            $table->string('last_error_code', 128)->default('')->comment('最后错误编码');
            $table->text('last_error_message')->default('')->comment('最后错误信息');
            $table->string('failure_detail_ref', 255)->default('')->comment('失败详情引用');
            $table->timestamp('started_at')->nullable()->comment('开始时间');
            $table->timestamp('finished_at')->nullable()->comment('完成时间');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['resource_id', 'status', 'created_at']);
            $table->index('version_id');
        });

        Schema::create('schema_audit_events', static function (Blueprint $table): void {
            $table->id();
            foreach (['kind' => 64, 'category' => 64, 'action' => 128, 'status' => 32, 'resource_key' => 191, 'record_id' => 128, 'subject_type' => 64, 'subject_id' => 128, 'actor_type' => 64, 'actor_id' => 128, 'request_id' => 128, 'trace_id' => 128, 'tenant_id' => 128, 'remote_ip' => 64] as $name => $length) {
                $table->string($name, $length)->default('')->comment('审计字段 '.$name);
            }
            $table->text('user_agent')->default('')->comment('客户端 User-Agent');
            $table->json('payload_json')->comment('审计载荷');
            $table->timestamp('occurred_at')->useCurrent()->comment('发生时间');
            $table->index(['resource_key', 'record_id', 'occurred_at']);
            $table->index(['category', 'action', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schema_publish_tasks');
        Schema::dropIfExists('schema_field_projections');
        Schema::dropIfExists('schema_resource_versions');
        Schema::dropIfExists('schema_resources');
        Schema::dropIfExists('schema_audit_events');
    }
};
