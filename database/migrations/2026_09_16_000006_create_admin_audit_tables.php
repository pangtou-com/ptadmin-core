<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('admin_login_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable()->comment('匹配到的管理员主键');
            $table->string('username', 100)->comment('本次尝试使用的登录用户名');
            $table->boolean('successful')->comment('本次登录是否成功');
            $table->string('failure_reason', 100)->default('')->comment('失败原因稳定编码');
            $table->string('ip_address', 45)->default('')->comment('登录请求 IP 地址');
            $table->text('user_agent')->comment('登录请求 User-Agent');
            $table->dateTime('created_at');
            $table->index(['admin_id', 'created_at']);
            $table->index(['username', 'created_at']);
        });

        Schema::create('admin_operation_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id')->comment('执行操作的管理员主键');
            $table->string('request_id', 128)->default('')->comment('关联请求追踪标识');
            $table->string('resource_key', 191)->default('')->comment('操作涉及的权限或 Schema 资源编码');
            $table->string('action', 100)->comment('操作动作或路由名称');
            $table->string('method', 10)->comment('HTTP 请求方法');
            $table->string('path', 500)->comment('不含查询参数的请求路径');
            $table->unsignedSmallInteger('status_code')->comment('HTTP 响应状态码');
            $table->unsignedInteger('response_time_ms')->default(0)->comment('请求从进入后台审计到生成响应的耗时，单位毫秒');
            $table->json('request_payload')->nullable()->comment('脱敏并限量后的写请求参数 JSON');
            $table->boolean('successful')->comment('操作是否得到成功响应');
            $table->string('ip_address', 45)->default('')->comment('操作请求 IP 地址');
            $table->dateTime('created_at');
            $table->index(['admin_id', 'created_at']);
            $table->index(['resource_key', 'created_at']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_operation_records');
        Schema::dropIfExists('admin_login_logs');
    }
};
