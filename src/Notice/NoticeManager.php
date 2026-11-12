<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

use Illuminate\Database\ConnectionInterface;
use PTAdmin\Notice\Contracts\NoticeChannelBatchAdapterInterface;
use PTAdmin\Notice\Contracts\NoticeChannelAdapterInterface;
use Throwable;

final class NoticeManager
{
    public function __construct(
        private ConnectionInterface $database,
        private NoticeTemplateResolver $templates,
        private NoticeChannelAdapterRegistry $adapters,
        private NoticeChannelRouter $router,
    ) {
    }

    /**
     * @param array<mixed> $targets
     * @param array<mixed> $parameters
     * @param array<mixed>|null $action
     */
    public function send(
        string $sceneCode,
        array $targets,
        array $parameters = [],
        string $locale = 'zh-CN',
        ?array $action = null,
        ?NoticeReceiver $receiver = null,
    ): NoticeSendResult {
        $sceneCode = strtolower(trim($sceneCode));
        $locale = trim($locale);
        if ($sceneCode === '' || $locale === '') {
            throw new NoticeSendingException('通知场景和语言区域不能为空。', 'notice_send_invalid');
        }
        $scene = $this->database->table('notice_scenes')->where('code', $sceneCode)->first();
        if ($scene === null) {
            throw new NoticeSendingException('通知场景不存在。', 'notice_scene_not_found', ['scene' => $sceneCode]);
        }
        if (!(bool) $scene->enabled) {
            throw new NoticeSendingException('通知场景已停用。', 'notice_scene_disabled', ['scene' => $sceneCode]);
        }

        $targets = $this->normalizeTargets($targets);
        $targets = $this->appendRecordedInAppTarget($scene, $targets, $receiver);
        $prepared = $this->prepareTargets($scene, $targets, $parameters, $locale, $action, $receiver);
        $results = [];
        $messageIds = [];

        $this->database->transaction(function () use ($prepared, $scene, &$results, &$messageIds): void {
            foreach ($prepared as $index => $target) {
                if ($target['template']->channelType !== 'in_app') {
                    continue;
                }
                $receiver = $target['target']->receiver;
                if ($receiver === null) {
                    throw new NoticeSendingException('站内信缺少接收主体。', 'notice_receiver_required');
                }
                $messageId = $this->insertInAppMessage($scene, $receiver, $target['template']);
                $messageIds[$receiver->key()] = $messageId;
                $results[$index] = new NoticeTargetResult(
                    channelType: 'in_app',
                    channel: $target['template']->channel,
                    status: 'delivered',
                    noticeMessageId: $messageId,
                );
            }
        });

        foreach ($prepared as $index => $target) {
            if ($target['template']->channelType === 'in_app') {
                continue;
            }
            $linkedMessageId = $receiver !== null ? ($messageIds[$receiver->key()] ?? null) : null;
            $results[$index] = $this->sendExternal($target, $receiver, $linkedMessageId);
        }
        ksort($results);

        return new NoticeSendResult($sceneCode, $locale, array_values($results));
    }

    /**
     * 批量发送同一场景和渠道的外部通知；每个目标仍对应一条独立投递记录。
     *
     * @param array<mixed> $destinations
     * @param array<mixed> $parameters
     * @param array<mixed>|null $action
     */
    public function sendBatch(
        string $sceneCode,
        string $channel,
        array $destinations,
        array $parameters = [],
        string $locale = 'zh-CN',
        ?array $action = null,
        ?NoticeReceiver $receiver = null,
    ): NoticeSendResult {
        $sceneCode = strtolower(trim($sceneCode));
        $channel = strtolower(trim($channel));
        $locale = trim($locale);
        if ($sceneCode === '' || $channel === '' || $locale === '') {
            throw new NoticeSendingException('通知场景、渠道和语言区域不能为空。', 'notice_send_invalid');
        }
        $scene = $this->database->table('notice_scenes')->where('code', $sceneCode)->first();
        if ($scene === null) {
            throw new NoticeSendingException('通知场景不存在。', 'notice_scene_not_found', ['scene' => $sceneCode]);
        }
        if (!(bool) $scene->enabled) {
            throw new NoticeSendingException('通知场景已停用。', 'notice_scene_disabled', ['scene' => $sceneCode]);
        }
        $normalized = [];
        foreach ($destinations as $destination) {
            if (!is_string($destination) || trim($destination) === '') {
                throw new NoticeSendingException('批量通知目标必须是非空字符串。', 'notice_destination_required');
            }
            $normalized[] = trim($destination);
        }
        $normalized = array_values(array_unique($normalized));
        if ($normalized === []) {
            throw new NoticeSendingException('批量通知目标不能为空。', 'notice_targets_required');
        }
        $template = $this->templates->resolve($sceneCode, $channel, $locale, $parameters, $action);
        if ($template->channelType === 'in_app') {
            throw new NoticeSendingException('批量通知不支持站内信渠道。', 'notice_batch_channel_invalid');
        }
        $adapter = $this->adapters->resolve($channel);
        $batchKey = bin2hex(random_bytes(16));
        $now = time();
        $messages = [];
        foreach ($normalized as $destination) {
            $deliveryId = (int) $this->database->table('notice_deliveries')->insertGetId([
                'notice_message_id' => null,
                'scene_code' => $template->scene,
                'channel' => $template->channel,
                'destination' => $destination,
                'batch_key' => $batchKey,
                'receiver_type' => $receiver?->type,
                'receiver_id' => $receiver?->id,
                'status' => 'pending',
                'provider_message_id' => null,
                'error_code' => null,
                'error_message' => null,
                'accepted_at' => null,
                'delivered_at' => null,
                'meta' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $messages[] = new NoticeChannelMessage($deliveryId, $destination, $template, $receiver, $batchKey);
        }

        try {
            if ($adapter instanceof NoticeChannelBatchAdapterInterface) {
                $batchResponse = $adapter->sendBatch($messages);
                if (count($batchResponse->responses) !== count($messages)) {
                    throw new NoticeSendingException('批量发送结果数量与目标数量不一致。', 'notice_batch_result_invalid');
                }
                $responses = $batchResponse->responses;
                foreach ($responses as $index => $response) {
                    $meta = $response->meta;
                    if ($batchResponse->providerBatchId !== null) {
                        $meta['provider_batch_id'] = $batchResponse->providerBatchId;
                    }
                    if ($batchResponse->meta !== []) {
                        $meta['batch'] = $batchResponse->meta;
                    }
                    $responses[$index] = $response->status === 'failed'
                        ? NoticeChannelResponse::failed($response->errorCode ?? 'notice_channel_send_failed', $response->errorMessage ?? '发送渠道返回失败。', $meta)
                        : ($response->status === 'delivered'
                            ? NoticeChannelResponse::delivered($response->providerMessageId, $meta)
                            : ($response->status === 'unknown'
                                ? NoticeChannelResponse::unknown($response->providerMessageId, $meta)
                                : NoticeChannelResponse::accepted($response->providerMessageId, $meta)));
                }
            } else {
                $responses = [];
                foreach ($messages as $message) {
                    try {
                        $responses[] = $adapter->send($message);
                    } catch (Throwable $exception) {
                        $responses[] = NoticeChannelResponse::failed(
                            $exception instanceof NoticeSendingException ? $exception->errorCode : 'notice_channel_send_failed',
                            $exception->getMessage(),
                        );
                    }
                }
            }
        } catch (Throwable $exception) {
            $responses = array_fill(0, count($messages), NoticeChannelResponse::failed(
                $exception instanceof NoticeSendingException ? $exception->errorCode : 'notice_channel_send_failed',
                $exception->getMessage(),
            ));
        }

        $results = [];
        foreach ($messages as $index => $message) {
            $response = $responses[$index] ?? NoticeChannelResponse::failed('notice_batch_result_invalid', '批量发送未返回当前目标结果。');
            $results[] = $this->persistExternalResponse($message, $response, null);
        }

        return new NoticeSendResult($sceneCode, $locale, $results);
    }

    /** @param array<mixed> $targets @return list<NoticeTarget> */
    private function normalizeTargets(array $targets): array
    {
        if ($targets === []) {
            throw new NoticeSendingException('通知发送目标不能为空。', 'notice_targets_required');
        }
        $normalized = [];
        $inAppReceivers = [];
        foreach ($targets as $target) {
            if (!$target instanceof NoticeTarget) {
                throw new NoticeSendingException('通知发送目标类型无效。', 'notice_target_invalid');
            }
            if ($target->type === 'in_app' && $target->receiver !== null) {
                if (isset($inAppReceivers[$target->receiver->key()])) {
                    continue;
                }
                $inAppReceivers[$target->receiver->key()] = true;
            }
            $normalized[] = $target;
        }

        return $normalized;
    }

    /** @param object $scene @param list<NoticeTarget> $targets @return list<NoticeTarget> */
    private function appendRecordedInAppTarget(object $scene, array $targets, ?NoticeReceiver $receiver): array
    {
        if (!(bool) ($scene->record_in_app ?? false)) {
            return $targets;
        }
        $allowed = $this->decodeArray($scene->channels ?? null);
        if ($allowed !== [] && !in_array('in_app', $allowed, true)) {
            throw new NoticeSendingException('通知场景要求记录站内信，但未允许 in_app 渠道。', 'notice_scene_invalid');
        }
        foreach ($targets as $target) {
            if ($target->type === 'in_app' && ($receiver === null || $target->receiver?->key() === $receiver->key())) {
                return $targets;
            }
        }
        if ($receiver === null) {
            throw new NoticeSendingException('该通知场景要求同步站内信，必须提供站内接收主体。', 'notice_receiver_required');
        }
        $targets[] = NoticeTarget::inApp($receiver);

        return $targets;
    }

    /**
     * @param object $scene
     * @param list<NoticeTarget> $targets
     * @param array<mixed> $parameters
     * @param array<mixed>|null $action
     * @return list<array{target:NoticeTarget,template:ResolvedNoticeTemplate,adapter:NoticeChannelAdapterInterface|null}>
     */
    private function prepareTargets(
        object $scene,
        array $targets,
        array $parameters,
        string $locale,
        ?array $action,
        ?NoticeReceiver $receiver,
    ): array {
        $prepared = [];
        $allowedTypes = $this->decodeArray($scene->channels ?? null);
        foreach ($targets as $target) {
            $channel = $target->channel;
            if ($channel === null) {
                $type = $target->type;
                if ($type === null || ($allowedTypes !== [] && !in_array($type, $allowedTypes, true))) {
                    throw new NoticeSendingException('通知场景不允许使用该发送渠道类型。', 'notice_channel_not_allowed', [
                        'scene' => (string) $scene->code,
                        'channel_type' => $type,
                    ]);
                }
                $context = new NoticeRoutingContext(
                    scene: (string) $scene->code,
                    channelType: $type,
                    locale: $locale,
                    destination: $target->destination,
                    parameters: $parameters,
                    action: $action,
                    receiver: $target->receiver ?? $receiver,
                );
                $channel = $this->router->select($context, $this->candidateChannels((int) $scene->id, $type, $locale));
            }

            $template = $this->templates->resolve((string) $scene->code, $channel, $locale, $parameters, $action);
            if ($target->type !== null && $template->channelType !== $target->type) {
                throw new NoticeSendingException('指定渠道类型与实际发送渠道不一致。', 'notice_channel_type_mismatch', [
                    'channel' => $channel,
                    'expected_type' => $target->type,
                    'actual_type' => $template->channelType,
                ]);
            }
            if ($template->channelType === 'in_app') {
                if ($template->channel !== NoticeChannel::IN_APP_CODE || $target->receiver === null) {
                    throw new NoticeSendingException('站内信必须使用 Core 内置渠道和明确接收主体。', 'notice_in_app_target_invalid');
                }
                $adapter = null;
            } else {
                if ($target->destination === null) {
                    throw new NoticeSendingException('外部发送渠道缺少接收地址。', 'notice_destination_required');
                }
                $adapter = $this->adapters->resolve($template->channel);
            }
            $prepared[] = ['target' => $target, 'template' => $template, 'adapter' => $adapter];
        }

        return $prepared;
    }

    /** @return list<string> */
    private function candidateChannels(int $sceneId, string $type, string $locale): array
    {
        $channels = $this->database->table('notice_channels')
            ->where('type', $type)
            ->where('enabled', true)
            ->orderBy('id')
            ->pluck('code');
        $candidates = [];
        foreach ($channels as $channel) {
            $channel = (string) $channel;
            if ($this->database->table('notice_templates')->where([
                'scene_id' => $sceneId,
                'channel' => $channel,
                'locale' => $locale,
                'enabled' => true,
            ])->exists()) {
                $candidates[] = $channel;
            }
        }

        return $candidates;
    }

    private function insertInAppMessage(object $scene, NoticeReceiver $receiver, ResolvedNoticeTemplate $template): int
    {
        if ($template->editor !== 'message') {
            throw new NoticeSendingException('站内信模板必须使用 message 编辑器。', 'notice_in_app_template_invalid');
        }
        $data = [
            'scene' => $template->scene,
            'parameters' => $template->parameters,
        ];
        if (isset($template->action['label'])) {
            $data['action_label'] = $template->action['label'];
        }
        $now = time();

        return (int) $this->database->table('notice_messages')->insertGetId([
            'receiver_type' => $receiver->type,
            'receiver_id' => $receiver->id,
            'source_type' => (string) $scene->source_type,
            'source_code' => (string) $scene->source_code,
            'category' => 'notice',
            'level' => 'info',
            'title' => (string) $template->config['title'],
            'content' => (string) $template->config['content'],
            'action_type' => $template->action['type'] ?? 'none',
            'action_url' => $template->action['target'] ?? null,
            'biz_type' => null,
            'biz_id' => null,
            'biz_key' => null,
            'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'created_by' => 0,
            'expires_at' => null,
            'read_at' => null,
            'archived_at' => null,
            'deleted_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array{target:NoticeTarget,template:ResolvedNoticeTemplate,adapter:NoticeChannelAdapterInterface|null} $prepared
     */
    private function sendExternal(array $prepared, ?NoticeReceiver $receiver, ?int $noticeMessageId): NoticeTargetResult
    {
        $template = $prepared['template'];
        $target = $prepared['target'];
        $adapter = $prepared['adapter'];
        if ($adapter === null || $target->destination === null) {
            throw new NoticeSendingException('外部发送目标没有可用适配器。', 'notice_channel_adapter_missing');
        }
        $now = time();
        $deliveryId = (int) $this->database->table('notice_deliveries')->insertGetId([
            'notice_message_id' => $noticeMessageId,
            'scene_code' => $template->scene,
            'channel' => $template->channel,
            'destination' => $target->destination,
            'batch_key' => null,
            'receiver_type' => $receiver?->type,
            'receiver_id' => $receiver?->id,
            'status' => 'pending',
            'provider_message_id' => null,
            'error_code' => null,
            'error_message' => null,
            'accepted_at' => null,
            'delivered_at' => null,
            'meta' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            $response = $adapter->send(new NoticeChannelMessage($deliveryId, $target->destination, $template, $receiver));
            return $this->persistExternalResponse(new NoticeChannelMessage($deliveryId, $target->destination, $template, $receiver), $response, $noticeMessageId);
        } catch (Throwable $exception) {
            $errorCode = $exception instanceof NoticeSendingException ? $exception->errorCode : 'notice_channel_send_failed';
            $this->database->table('notice_deliveries')->where('id', $deliveryId)->update([
                'status' => 'failed',
                'error_code' => $errorCode,
                'error_message' => $exception->getMessage(),
                'updated_at' => time(),
            ]);

            return new NoticeTargetResult(
                channelType: $template->channelType,
                channel: $template->channel,
                status: 'failed',
                noticeMessageId: $noticeMessageId,
                deliveryId: $deliveryId,
                errorCode: $errorCode,
                errorMessage: $exception->getMessage(),
            );
        }
    }

    private function persistExternalResponse(NoticeChannelMessage $message, NoticeChannelResponse $response, ?int $noticeMessageId): NoticeTargetResult
    {
        $errorCode = $response->status === 'failed' ? ($response->errorCode ?: 'notice_channel_send_failed') : null;
        $errorMessage = $response->status === 'failed' ? ($response->errorMessage ?: '发送渠道返回失败。') : null;
        $completedAt = time();
        $this->database->table('notice_deliveries')->where('id', $message->deliveryId)->update([
            'status' => $response->status,
            'provider_message_id' => $response->providerMessageId,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'accepted_at' => in_array($response->status, ['accepted', 'delivered'], true) ? $completedAt : null,
            'delivered_at' => $response->status === 'delivered' ? $completedAt : null,
            'meta' => $response->meta !== [] ? json_encode($response->meta, JSON_THROW_ON_ERROR) : null,
            'updated_at' => $completedAt,
        ]);

        return new NoticeTargetResult(
            channelType: $message->template->channelType,
            channel: $message->template->channel,
            status: $response->status,
            noticeMessageId: $noticeMessageId,
            deliveryId: $message->deliveryId,
            providerMessageId: $response->providerMessageId,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }

    /** @return array<mixed> */
    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            throw new NoticeSendingException('通知场景定义包含无效 JSON。', 'notice_scene_invalid');
        }

        return $decoded;
    }
}
