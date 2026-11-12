<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('notice_deliveries', static function (Blueprint $table): void {
            $table->string('destination', 255)->nullable()->after('channel')->comment('外部渠道投递目标，例如手机号或邮箱地址');
            $table->string('batch_key', 100)->nullable()->after('destination')->comment('同一次批量通知的本地关联标识');
            $table->index(['channel', 'destination'], 'notice_deliveries_channel_destination_index');
            $table->index('batch_key', 'notice_deliveries_batch_key_index');
        });
    }

    public function down(): void
    {
        Schema::table('notice_deliveries', static function (Blueprint $table): void {
            $table->dropIndex('notice_deliveries_channel_destination_index');
            $table->dropIndex('notice_deliveries_batch_key_index');
            $table->dropColumn(['destination', 'batch_key']);
        });
    }
};
