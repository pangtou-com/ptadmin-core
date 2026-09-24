<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('notice_channels', static function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 20)->default('addon')->comment('发送渠道来源类型，例如 system 或 addon');
            $table->string('source_code', 100)->comment('发送渠道来源稳定编码');
            $table->string('code', 150)->unique()->comment('发送渠道稳定编码');
            $table->string('type', 30)->comment('发送渠道类型，例如 sms、email 或 in_app');
            $table->string('title', 255)->comment('发送渠道名称');
            $table->string('editor', 30)->default('content')->comment('发送渠道模板编辑模式');
            $table->boolean('enabled')->default(true)->comment('发送渠道是否启用');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->index(['source_type', 'source_code']);
            $table->index(['type', 'enabled']);
        });

        Schema::create('notice_scenes', static function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 20)->default('addon')->comment('场景来源类型，例如 system 或 addon');
            $table->string('source_code', 100)->comment('场景来源稳定编码');
            $table->string('group_code', 50)->default('general')->comment('通知业务分组编码');
            $table->string('group_title', 100)->default('常规通知')->comment('通知业务分组名称');
            $table->string('code', 100)->unique()->comment('通知场景稳定编码');
            $table->string('title', 255)->comment('通知场景名称');
            $table->string('description', 500)->nullable()->comment('通知场景用途说明');
            $table->string('purpose', 30)->default('transactional')->comment('通知用途类型');
            $table->json('parameters')->nullable()->comment('通知场景定义方声明的模板参数');
            $table->json('channels')->nullable()->comment('通知场景允许使用的发送渠道类型');
            $table->json('supports')->nullable()->comment('通知场景支持的结构化能力，例如 action');
            $table->boolean('record_in_app')->default(false)->comment('发送其他渠道时是否同步记录站内信');
            $table->boolean('enabled')->default(true)->comment('通知场景是否启用');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->index(['source_type', 'source_code']);
            $table->index(['group_code', 'enabled']);
        });

        Schema::create('notice_templates', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scene_id')->comment('通知场景主键');
            $table->string('channel', 150)->comment('模板发送渠道稳定编码');
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

        Schema::create('notice_messages', static function (Blueprint $table): void {
            $table->id();
            $table->string('receiver_type', 20)->comment('站内消息接收主体类型');
            $table->unsignedBigInteger('receiver_id')->comment('站内消息接收主体主键');
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
            $table->string('biz_key', 150)->nullable()->comment('消息业务幂等键');
            $table->json('data')->nullable()->comment('消息扩展数据');
            $table->unsignedBigInteger('created_by')->default(0)->comment('消息创建主体主键');
            $table->unsignedInteger('expires_at')->nullable()->comment('消息过期时间戳');
            $table->unsignedInteger('read_at')->nullable()->comment('站内消息读取时间戳');
            $table->unsignedInteger('archived_at')->nullable()->comment('站内消息归档时间戳');
            $table->unsignedInteger('deleted_at')->nullable()->comment('站内消息删除时间戳');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->index(['receiver_type', 'receiver_id', 'deleted_at'], 'notice_messages_receiver_index');
            $table->index(['receiver_type', 'receiver_id', 'read_at'], 'notice_messages_unread_index');
            $table->unique(['receiver_type', 'receiver_id', 'biz_key'], 'notice_messages_receiver_biz_unique');
            $table->index(['receiver_type', 'category']);
            $table->index(['source_type', 'source_code']);
            $table->index(['biz_type', 'biz_id']);
        });

        Schema::create('notice_deliveries', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('notice_message_id')->nullable()->comment('同步生成的站内消息主键');
            $table->string('scene_code', 100)->comment('通知场景稳定编码');
            $table->string('channel', 150)->comment('外部发送渠道稳定编码');
            $table->string('receiver_type', 20)->nullable()->comment('外部投递接收主体类型');
            $table->unsignedBigInteger('receiver_id')->nullable()->comment('外部投递接收主体主键');
            $table->string('status', 50)->default('pending')->comment('外部投递状态');
            $table->string('provider_message_id', 150)->nullable()->comment('外部服务消息标识');
            $table->string('error_code', 100)->nullable()->comment('外部投递失败错误码');
            $table->text('error_message')->nullable()->comment('外部投递失败原因');
            $table->unsignedInteger('accepted_at')->nullable()->comment('外部服务受理时间戳');
            $table->unsignedInteger('delivered_at')->nullable()->comment('外部服务送达时间戳');
            $table->json('meta')->nullable()->comment('外部投递上下文');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->index(['notice_message_id', 'channel']);
            $table->index(['scene_code', 'channel']);
            $table->index(['receiver_type', 'receiver_id']);
            $table->index('provider_message_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_deliveries');
        Schema::dropIfExists('notice_messages');
        Schema::dropIfExists('notice_templates');
        Schema::dropIfExists('notice_scenes');
        Schema::dropIfExists('notice_channels');
    }
};
