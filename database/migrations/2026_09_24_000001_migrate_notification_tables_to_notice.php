<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /** @var array<string, string> */
    private const TABLES = [
        'notification_scenes' => 'notice_scenes',
        'notification_templates' => 'notice_templates',
        'notification_scene_routes' => 'notice_scene_routes',
        'notification_messages' => 'notice_messages',
        'notification_receipts' => 'notice_receipts',
        'notification_deliveries' => 'notice_deliveries',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $legacy => $current) {
            if (Schema::hasTable($legacy) && !Schema::hasTable($current)) {
                Schema::rename($legacy, $current);
            }
        }

        $this->upgradeScenes();
        $this->widenChannelCodes();
        if (!Schema::hasTable('notice_channels')) {
            $this->createChannels();
        }

        $receiptMessages = $this->upgradeMessages();
        $this->upgradeDeliveries($receiptMessages);

        Schema::dropIfExists('notice_receipts');
        Schema::dropIfExists('notice_scene_routes');
        $this->disableLegacyReceiptResource();
    }

    public function down(): void
    {
        // 该迁移会把一条旧消息拆成多条接收人消息，无法无损恢复旧结构，因此不提供逆向迁移。
    }

    private function upgradeScenes(): void
    {
        if (!Schema::hasTable('notice_scenes')) {
            return;
        }
        if (!Schema::hasColumn('notice_scenes', 'parameters')) {
            Schema::table('notice_scenes', static function (Blueprint $table): void {
                $table->json('parameters')->nullable()->comment('通知场景定义方声明的模板参数');
                $table->json('channels')->nullable()->comment('通知场景允许使用的发送渠道类型');
                $table->json('supports')->nullable()->comment('通知场景支持的结构化能力，例如 action');
            });
            if (Schema::hasColumn('notice_scenes', 'variables')) {
                DB::table('notice_scenes')->orderBy('id')->chunkById(100, static function ($scenes): void {
                    foreach ($scenes as $scene) {
                        DB::table('notice_scenes')->where('id', $scene->id)->update([
                            'parameters' => $scene->variables,
                            'channels' => json_encode([], JSON_THROW_ON_ERROR),
                            'supports' => json_encode([], JSON_THROW_ON_ERROR),
                        ]);
                    }
                });
            }
            $hasVariables = Schema::hasColumn('notice_scenes', 'variables');
            $hasDefaultChannels = Schema::hasColumn('notice_scenes', 'default_channels');
            Schema::table('notice_scenes', static function (Blueprint $table) use ($hasVariables, $hasDefaultChannels): void {
                if ($hasVariables) {
                    $table->dropColumn('variables');
                }
                if ($hasDefaultChannels) {
                    $table->dropColumn('default_channels');
                }
            });
        }
        if (!Schema::hasColumn('notice_scenes', 'record_in_app')) {
            Schema::table('notice_scenes', static function (Blueprint $table): void {
                $table->boolean('record_in_app')->default(false)->comment('发送其他渠道时是否同步记录站内信');
            });
        }
    }

    private function widenChannelCodes(): void
    {
        $comments = [
            'notice_templates' => '模板发送渠道稳定编码',
            'notice_deliveries' => '外部发送渠道稳定编码',
        ];
        $driver = DB::connection()->getDriverName();
        foreach ($comments as $tableName => $comment) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'channel')) {
                continue;
            }
            $wrappedTable = DB::connection()->getQueryGrammar()->wrapTable($tableName);
            if ($driver === 'mysql') {
                DB::statement(sprintf(
                    'ALTER TABLE %s MODIFY `channel` VARCHAR(150) NOT NULL COMMENT %s',
                    $wrappedTable,
                    DB::connection()->getPdo()->quote($comment),
                ));
            } elseif ($driver === 'pgsql') {
                DB::statement(sprintf('ALTER TABLE %s ALTER COLUMN "channel" TYPE VARCHAR(150)', $wrappedTable));
                DB::statement(sprintf(
                    'COMMENT ON COLUMN %s."channel" IS %s',
                    $wrappedTable,
                    DB::connection()->getPdo()->quote($comment),
                ));
            } elseif ($driver === 'sqlsrv') {
                DB::statement(sprintf('ALTER TABLE %s ALTER COLUMN [channel] NVARCHAR(150) NOT NULL', $wrappedTable));
            }
        }
    }

    private function createChannels(): void
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
    }

    /** @return array<int, int> 旧回执主键到新站内消息主键的映射 */
    private function upgradeMessages(): array
    {
        if (!Schema::hasTable('notice_messages')) {
            return [];
        }

        $missing = [
            'receiver_type' => !Schema::hasColumn('notice_messages', 'receiver_type'),
            'receiver_id' => !Schema::hasColumn('notice_messages', 'receiver_id'),
            'read_at' => !Schema::hasColumn('notice_messages', 'read_at'),
            'archived_at' => !Schema::hasColumn('notice_messages', 'archived_at'),
            'deleted_at' => !Schema::hasColumn('notice_messages', 'deleted_at'),
        ];
        if (in_array(true, $missing, true)) {
            Schema::table('notice_messages', static function (Blueprint $table) use ($missing): void {
                if ($missing['receiver_type']) {
                    $table->string('receiver_type', 20)->nullable()->comment('站内消息接收主体类型');
                }
                if ($missing['receiver_id']) {
                    $table->unsignedBigInteger('receiver_id')->nullable()->comment('站内消息接收主体主键');
                }
                if ($missing['read_at']) {
                    $table->unsignedInteger('read_at')->nullable()->comment('站内消息读取时间戳');
                }
                if ($missing['archived_at']) {
                    $table->unsignedInteger('archived_at')->nullable()->comment('站内消息归档时间戳');
                }
                if ($missing['deleted_at']) {
                    $table->unsignedInteger('deleted_at')->nullable()->comment('站内消息删除时间戳');
                }
            });
        }

        if (Schema::hasColumn('notice_messages', 'audience_type')) {
            $this->rebuildMessages();
        }

        $receiptMessages = [];
        if (Schema::hasTable('notice_receipts')) {
            $noticeColumn = Schema::hasColumn('notice_receipts', 'notice_id') ? 'notice_id' : 'notification_id';
            $assignedMessages = [];
            $receipts = DB::table('notice_receipts')->orderBy($noticeColumn)->orderBy('id')->get();
            foreach ($receipts as $receipt) {
                $messageId = (int) $receipt->{$noticeColumn};
                $message = DB::table('notice_messages')->where('id', $messageId)->first();
                if ($message === null) {
                    continue;
                }

                $recipient = [
                    'receiver_type' => $receipt->receiver_type,
                    'receiver_id' => $receipt->receiver_id,
                    'read_at' => $receipt->read_at,
                    'archived_at' => $receipt->archived_at,
                    'deleted_at' => $receipt->deleted_at,
                ];
                if (!isset($assignedMessages[$messageId])) {
                    DB::table('notice_messages')->where('id', $messageId)->update($recipient);
                    $newMessageId = $messageId;
                    $assignedMessages[$messageId] = true;
                } else {
                    $duplicate = array_merge((array) $message, $recipient);
                    unset($duplicate['id']);
                    $newMessageId = (int) DB::table('notice_messages')->insertGetId($duplicate);
                }
                $receiptMessages[(int) $receipt->id] = $newMessageId;
            }
        }

        return $receiptMessages;
    }

    /** @param array<int, int> $receiptMessages */
    private function upgradeDeliveries(array $receiptMessages): void
    {
        if (!Schema::hasTable('notice_deliveries')) {
            return;
        }

        $missing = [
            'notice_message_id' => !Schema::hasColumn('notice_deliveries', 'notice_message_id'),
            'scene_code' => !Schema::hasColumn('notice_deliveries', 'scene_code'),
            'provider_message_id' => !Schema::hasColumn('notice_deliveries', 'provider_message_id'),
            'error_code' => !Schema::hasColumn('notice_deliveries', 'error_code'),
        ];
        if (in_array(true, $missing, true)) {
            Schema::table('notice_deliveries', static function (Blueprint $table) use ($missing): void {
                if ($missing['notice_message_id']) {
                    $table->unsignedBigInteger('notice_message_id')->nullable()->comment('同步生成的站内消息主键');
                }
                if ($missing['scene_code']) {
                    $table->string('scene_code', 100)->default('legacy')->comment('通知场景稳定编码');
                }
                if ($missing['provider_message_id']) {
                    $table->string('provider_message_id', 150)->nullable()->comment('外部服务消息标识');
                }
                if ($missing['error_code']) {
                    $table->string('error_code', 100)->nullable()->comment('外部投递失败错误码');
                }
            });
        }

        $noticeColumn = Schema::hasColumn('notice_deliveries', 'notice_id')
            ? 'notice_id'
            : (Schema::hasColumn('notice_deliveries', 'notification_id') ? 'notification_id' : null);
        $hasReceiptId = Schema::hasColumn('notice_deliveries', 'receipt_id');
        $hasMessageId = Schema::hasColumn('notice_deliveries', 'message_id');
        $hasRaw = Schema::hasColumn('notice_deliveries', 'raw');

        DB::table('notice_deliveries')->orderBy('id')->chunkById(100, function ($deliveries) use (
            $receiptMessages,
            $noticeColumn,
            $hasReceiptId,
            $hasMessageId,
            $hasRaw,
        ): void {
            foreach ($deliveries as $delivery) {
                $values = [];
                $receiptId = $hasReceiptId ? (int) ($delivery->receipt_id ?? 0) : 0;
                if ($receiptId > 0 && isset($receiptMessages[$receiptId])) {
                    $values['notice_message_id'] = $receiptMessages[$receiptId];
                } elseif ($noticeColumn !== null && $delivery->{$noticeColumn} !== null) {
                    $values['notice_message_id'] = (int) $delivery->{$noticeColumn};
                }
                if ($hasMessageId) {
                    $values['provider_message_id'] = $delivery->message_id;
                }
                if ($hasRaw && $delivery->raw !== null) {
                    $meta = json_decode((string) ($delivery->meta ?? ''), true);
                    $raw = json_decode((string) $delivery->raw, true);
                    $metadata = is_array($meta) ? $meta : [];
                    $metadata['legacy_raw'] = $raw ?? $delivery->raw;
                    $values['meta'] = json_encode($metadata, JSON_THROW_ON_ERROR);
                }
                if ($values !== []) {
                    DB::table('notice_deliveries')->where('id', $delivery->id)->update($values);
                }
            }
        });

        $obsolete = array_values(array_filter([
            'notice_id',
            'notification_id',
            'receipt_id',
            'provider',
            'addon_code',
            'provider_group',
            'instance_code',
            'route_revision',
            'strategy',
            'message_id',
            'batch_id',
            'raw',
        ], static fn (string $column): bool => Schema::hasColumn('notice_deliveries', $column)));
        if (in_array(true, $missing, true) || $obsolete !== []) {
            $this->rebuildDeliveries();
        }
    }

    private function rebuildMessages(): void
    {
        Schema::dropIfExists('notice_messages_next');
        Schema::create('notice_messages_next', static function (Blueprint $table): void {
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
            $table->index(['receiver_type', 'receiver_id', 'deleted_at'], 'notice_messages_next_receiver_index');
            $table->index(['receiver_type', 'receiver_id', 'read_at'], 'notice_messages_next_unread_index');
            $table->unique(['receiver_type', 'receiver_id', 'biz_key'], 'notice_messages_next_receiver_biz_unique');
            $table->index(['receiver_type', 'category']);
            $table->index(['source_type', 'source_code']);
            $table->index(['biz_type', 'biz_id']);
        });

        foreach (DB::table('notice_messages')->orderBy('id')->get() as $message) {
            $values = [
                'id' => $message->id,
                'receiver_type' => $message->receiver_type ?? $message->audience_type ?? 'admin',
                'receiver_id' => $message->receiver_id ?? 0,
                'title' => $message->title,
            ];
            foreach ([
                'source_type', 'source_code', 'category', 'level', 'content', 'action_type', 'action_url',
                'biz_type', 'biz_id', 'biz_key', 'data', 'created_by', 'expires_at', 'read_at', 'archived_at',
                'deleted_at', 'created_at', 'updated_at',
            ] as $column) {
                if (property_exists($message, $column)) {
                    $values[$column] = $message->{$column};
                }
            }
            DB::table('notice_messages_next')->insert($values);
        }

        Schema::drop('notice_messages');
        Schema::rename('notice_messages_next', 'notice_messages');
    }

    private function rebuildDeliveries(): void
    {
        Schema::dropIfExists('notice_deliveries_next');
        Schema::create('notice_deliveries_next', static function (Blueprint $table): void {
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

        foreach (DB::table('notice_deliveries')->orderBy('id')->get() as $delivery) {
            $values = [
                'id' => $delivery->id,
                'scene_code' => $delivery->scene_code,
                'channel' => $delivery->channel,
                'status' => $delivery->status,
            ];
            foreach ([
                'notice_message_id', 'receiver_type', 'receiver_id', 'provider_message_id', 'error_code',
                'error_message', 'accepted_at', 'delivered_at', 'meta', 'created_at', 'updated_at',
            ] as $column) {
                if (property_exists($delivery, $column)) {
                    $values[$column] = $delivery->{$column};
                }
            }
            DB::table('notice_deliveries_next')->insert($values);
        }

        Schema::drop('notice_deliveries');
        Schema::rename('notice_deliveries_next', 'notice_deliveries');
    }

    private function disableLegacyReceiptResource(): void
    {
        if (!Schema::hasTable('schema_resources')) {
            return;
        }

        DB::table('schema_resources')
            ->where('resource_key', 'admin.notification_receipts')
            ->update([
                'status' => 'disabled',
                'updated_at' => now(),
            ]);
    }
};
