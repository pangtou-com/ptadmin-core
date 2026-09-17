<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('notification_scenes', static function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 20)->default('addon')->comment('场景来源类型，例如 system 或 addon');
            $table->string('source_code', 100)->comment('场景来源稳定编码');
            $table->string('group_code', 50)->default('general')->comment('通知业务分组编码');
            $table->string('group_title', 100)->default('常规通知')->comment('通知业务分组名称');
            $table->string('code', 100)->unique()->comment('通知场景稳定编码');
            $table->string('title', 255)->comment('通知场景名称');
            $table->string('description', 500)->nullable()->comment('通知场景用途说明');
            $table->string('purpose', 30)->default('transactional')->comment('通知用途类型');
            $table->json('variables')->nullable()->comment('通知模板可用变量定义');
            $table->json('default_channels')->nullable()->comment('场景默认投递渠道');
            $table->boolean('enabled')->default(true)->comment('通知场景是否启用');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->index(['source_type', 'source_code']);
            $table->index(['group_code', 'enabled']);
        });

        Schema::create('notification_templates', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scene_id')->comment('通知场景主键');
            $table->string('channel', 50)->comment('模板投递渠道');
            $table->string('locale', 20)->default('zh-CN')->comment('模板语言区域');
            $table->string('mode', 20)->comment('模板内容模式');
            $table->json('config')->nullable()->comment('模板内容与渠道配置');
            $table->boolean('customized')->default(false)->comment('是否已被项目自定义');
            $table->boolean('enabled')->default(true)->comment('通知模板是否启用');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->unique(['scene_id', 'channel', 'locale']);
            $table->index(['channel', 'enabled']);
        });

        Schema::create('notification_scene_routes', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scene_id')->comment('通知场景主键');
            $table->string('channel', 50)->comment('通知投递渠道');
            $table->string('addon_code', 100)->nullable()->comment('渠道提供插件编码');
            $table->string('provider_group', 50)->comment('渠道提供者分组');
            $table->string('provider', 100)->comment('渠道提供者编码');
            $table->string('instance_code', 100)->comment('渠道实例稳定编码');
            $table->string('dispatch_mode', 20)->comment('渠道调度模式');
            $table->string('strategy', 30)->nullable()->comment('渠道选择策略');
            $table->unsignedInteger('priority')->default(0)->comment('渠道优先级');
            $table->unsignedInteger('weight')->default(100)->comment('渠道选择权重');
            $table->unsignedInteger('revision')->default(1)->comment('渠道配置修订号');
            $table->boolean('enabled')->default(true)->comment('场景渠道是否启用');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->unique(
                ['scene_id', 'channel', 'addon_code', 'provider_group', 'provider', 'instance_code'],
                'notification_scene_routes_instance_unique'
            );
            $table->index(['scene_id', 'channel', 'enabled']);
        });

        Schema::create('notification_messages', static function (Blueprint $table): void {
            $table->id();
            $table->string('audience_type', 20)->default('admin')->comment('消息接收对象类型');
            $table->string('source_type', 20)->default('system')->comment('消息来源类型');
            $table->string('source_code', 100)->default('system')->comment('消息来源稳定编码');
            $table->string('category', 50)->default('notice')->comment('消息分类');
            $table->string('level', 20)->default('info')->comment('消息等级');
            $table->string('title', 255)->comment('消息标题');
            $table->text('content')->nullable()->comment('消息正文');
            $table->string('action_type', 20)->default('none')->comment('消息点击动作类型');
            $table->string('action_url', 500)->nullable()->comment('消息点击动作地址');
            $table->string('biz_type', 100)->nullable()->comment('关联业务类型');
            $table->string('biz_id', 100)->nullable()->comment('关联业务标识');
            $table->string('biz_key', 150)->nullable()->unique()->comment('消息业务幂等键');
            $table->json('data')->nullable()->comment('消息扩展数据');
            $table->unsignedBigInteger('created_by')->default(0)->comment('消息创建主体主键');
            $table->unsignedInteger('expires_at')->nullable()->comment('消息过期时间戳');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->index(['audience_type', 'category']);
            $table->index(['source_type', 'source_code']);
            $table->index(['biz_type', 'biz_id']);
        });

        Schema::create('notification_receipts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('notification_id')->comment('通知消息主键');
            $table->string('receiver_type', 20)->comment('消息接收主体类型');
            $table->unsignedBigInteger('receiver_id')->comment('消息接收主体主键');
            $table->unsignedInteger('read_at')->nullable()->comment('消息读取时间戳');
            $table->unsignedInteger('archived_at')->nullable()->comment('消息归档时间戳');
            $table->unsignedInteger('deleted_at')->nullable()->comment('接收记录删除时间戳');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->unique(['notification_id', 'receiver_type', 'receiver_id']);
            $table->index(['receiver_type', 'receiver_id', 'deleted_at']);
            $table->index(['receiver_type', 'receiver_id', 'read_at']);
        });

        Schema::create('notification_deliveries', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('notification_id')->nullable()->comment('通知消息主键');
            $table->unsignedBigInteger('receipt_id')->nullable()->comment('通知接收记录主键');
            $table->string('receiver_type', 20)->nullable()->comment('外部投递接收主体类型');
            $table->unsignedBigInteger('receiver_id')->nullable()->comment('外部投递接收主体主键');
            $table->string('channel', 50)->comment('外部投递渠道');
            $table->string('provider', 100)->nullable()->comment('外部投递提供者编码');
            $table->string('addon_code', 100)->nullable()->comment('外部投递提供插件编码');
            $table->string('provider_group', 50)->nullable()->comment('外部投递提供者分组');
            $table->string('instance_code', 100)->nullable()->comment('外部投递实例编码');
            $table->unsignedInteger('route_revision')->nullable()->comment('使用的场景路由修订号');
            $table->string('strategy', 30)->nullable()->comment('使用的渠道选择策略');
            $table->string('message_id', 150)->nullable()->comment('外部服务消息标识');
            $table->string('batch_id', 150)->nullable()->comment('外部服务批次标识');
            $table->string('status', 50)->default('pending')->comment('外部投递状态');
            $table->unsignedInteger('accepted_at')->nullable()->comment('外部服务受理时间戳');
            $table->unsignedInteger('delivered_at')->nullable()->comment('外部服务送达时间戳');
            $table->text('error_message')->nullable()->comment('外部投递失败原因');
            $table->json('meta')->nullable()->comment('外部投递上下文');
            $table->json('raw')->nullable()->comment('外部服务原始响应');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->index(['notification_id', 'channel']);
            $table->index(['receiver_type', 'receiver_id']);
            $table->index(['provider', 'message_id']);
            $table->index(['addon_code', 'provider_group', 'provider', 'instance_code'], 'notification_deliveries_instance_index');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_receipts');
        Schema::dropIfExists('notification_messages');
        Schema::dropIfExists('notification_scene_routes');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notification_scenes');
    }
};
