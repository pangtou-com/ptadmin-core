<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ptadmin_sessions', function (Blueprint $table): void {
            $table->string('id', 32)->primary();
            $table->string('token_hash', 64)->unique()->comment('Bearer Token SHA-256 摘要');
            $table->string('subject_type', 100)->comment('会话主体类型');
            $table->string('subject_id', 100)->comment('会话主体标识');
            $table->string('guard_name', 50)->comment('认证 Guard 名称');
            $table->dateTime('issued_at')->comment('签发时间');
            $table->dateTime('expires_at')->comment('当前空闲过期时间');
            $table->dateTime('absolute_expires_at')->comment('绝对过期时间');
            $table->dateTime('last_used_at')->nullable()->comment('最近使用时间');
            $table->dateTime('revoked_at')->nullable()->comment('撤销时间');
            $table->json('metadata')->nullable()->comment('非敏感会话元数据');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['subject_type', 'subject_id', 'guard_name'], 'ptadmin_sessions_subject_index');
            $table->index(['expires_at', 'revoked_at'], 'ptadmin_sessions_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ptadmin_sessions');
    }
};
