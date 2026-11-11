<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', static function (Blueprint $table): void {
                $table->id();
                $table->string('name')->comment('会员公开昵称');
                $table->string('email')->unique()->comment('会员登录邮箱');
                $table->string('password')->comment('会员密码哈希');
                $table->boolean('status')->default(true)->comment('会员账号是否启用');
                $table->timestamp('email_verified_at')->nullable()->comment('可信邮箱验证完成时间');
                $table->timestamp('phone_verified_at')->nullable()->comment('可信手机号验证完成时间');
                $table->timestamp('realname_verified_at')->nullable()->comment('可信实名验证完成时间');
                $table->rememberToken()->comment('会员持续登录令牌');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', static function (Blueprint $table): void {
                $table->string('email')->primary()->comment('申请密码重置的会员邮箱');
                $table->string('token')->comment('密码重置令牌哈希');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // 会员表可能由宿主提供；回滚不删除账号或密码重置资料。
    }
};
