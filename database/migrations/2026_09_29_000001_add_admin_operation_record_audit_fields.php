<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('admin_operation_records')) {
            return;
        }

        $missing = [
            'response_time_ms' => !Schema::hasColumn('admin_operation_records', 'response_time_ms'),
            'request_payload' => !Schema::hasColumn('admin_operation_records', 'request_payload'),
        ];
        if (!in_array(true, $missing, true)) {
            return;
        }

        Schema::table('admin_operation_records', static function (Blueprint $table) use ($missing): void {
            if ($missing['response_time_ms']) {
                $table->unsignedInteger('response_time_ms')->default(0)->comment('请求从进入后台审计到生成响应的耗时，单位毫秒');
            }
            if ($missing['request_payload']) {
                $table->json('request_payload')->nullable()->comment('脱敏并限量后的写请求参数 JSON');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('admin_operation_records')) {
            return;
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('admin_operation_records', 'response_time_ms') ? 'response_time_ms' : null,
            Schema::hasColumn('admin_operation_records', 'request_payload') ? 'request_payload' : null,
        ]));
        if ($columns === []) {
            return;
        }

        Schema::table('admin_operation_records', static function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }
};
