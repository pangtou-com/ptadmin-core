<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->string('username', 100)->unique()->comment('登录用户名');
            $table->string('nickname', 100)->default('')->comment('管理员显示名称');
            $table->string('password')->comment('密码哈希');
            $table->string('email')->nullable()->comment('电子邮箱');
            $table->string('mobile', 30)->nullable()->comment('手机号码');
            $table->string('avatar')->nullable()->comment('头像地址');
            $table->boolean('status')->default(true)->comment('账号状态');
            $table->boolean('is_founder')->default(false)->comment('是否创始人');
            $table->timestamp('login_at')->nullable()->comment('最近登录时间');
            $table->string('login_ip', 45)->nullable()->comment('最近登录 IP');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
