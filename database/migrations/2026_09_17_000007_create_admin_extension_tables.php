<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('admin_dashboard_widgets', static function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 20)->comment('布局主体类型，例如 admin 或 role');
            $table->unsignedBigInteger('subject_id')->comment('布局主体主键');
            $table->string('widget_code', 150)->comment('仪表盘组件稳定编码');
            $table->boolean('enabled')->default(true)->comment('组件是否启用');
            $table->integer('sort')->default(0)->comment('组件排序值');
            $table->text('layout_json')->nullable()->comment('组件布局参数');
            $table->text('config_json')->nullable()->comment('组件实例配置');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->unique(
                ['subject_type', 'subject_id', 'widget_code'],
                'admin_dashboard_widgets_subject_unique'
            );
            $table->index(['subject_type', 'subject_id'], 'admin_dashboard_widgets_subject_index');
            $table->index('widget_code');
        });

        Schema::create('assets', static function (Blueprint $table): void {
            $table->id();
            $table->string('title', 255)->default('')->comment('资源展示名称');
            $table->string('original_title', 255)->default('')->comment('上传时的原始文件名称');
            $table->string('mime', 100)->default('')->comment('资源 MIME 类型');
            $table->string('suffix', 20)->default('')->comment('资源文件扩展名');
            $table->unsignedBigInteger('size')->default(0)->comment('资源字节大小');
            $table->string('disk_code', 50)->comment('公开资源所在的文件系统磁盘编码');
            $table->string('bucket', 100)->default('')->comment('对象存储 Bucket，本地磁盘保持为空');
            $table->string('path', 500)->default('')->comment('资源在存储磁盘中的对象路径');
            $table->string('group_code', 50)->default('default')->comment('资源中心业务分组编码');
            $table->unsignedInteger('ref_count')->default(0)->comment('业务记录对该资源的引用次数');
            $table->string('sha256', 64)->comment('资源内容 SHA-256 摘要');
            $table->text('metadata_json')->nullable()->comment('存储服务返回的扩展元数据');
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->unsignedInteger('deleted_at')->nullable();
            $table->index(['disk_code', 'path']);
            $table->index(['group_code', 'created_at']);
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
        Schema::dropIfExists('admin_dashboard_widgets');
    }
};
