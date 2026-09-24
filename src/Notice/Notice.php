<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

final class Notice
{
    /**
     * @param array<mixed> $parameters
     * @param array<mixed>|null $action
     */
    public static function send(
        string $scene,
        string $channel,
        string $to,
        array $parameters = [],
        string $locale = 'zh-CN',
        ?array $action = null,
        ?NoticeReceiver $receiver = null,
    ): NoticeSendResult {
        return app(NoticeManager::class)->send(
            $scene,
            [NoticeTarget::channel($channel, $to)],
            $parameters,
            $locale,
            $action,
            $receiver,
        );
    }

    /**
     * @param array<mixed> $parameters
     * @param array<mixed>|null $action
     */
    public static function sendSMS(
        string $scene,
        string $to,
        array $parameters = [],
        ?string $channel = null,
        string $locale = 'zh-CN',
        ?array $action = null,
        ?NoticeReceiver $receiver = null,
    ): NoticeSendResult {
        return app(NoticeManager::class)->send(
            $scene,
            [NoticeTarget::sms($to, $channel)],
            $parameters,
            $locale,
            $action,
            $receiver,
        );
    }

    /**
     * @param array<mixed> $parameters
     * @param array<mixed>|null $action
     */
    public static function sendEmail(
        string $scene,
        string $to,
        array $parameters = [],
        ?string $channel = null,
        string $locale = 'zh-CN',
        ?array $action = null,
        ?NoticeReceiver $receiver = null,
    ): NoticeSendResult {
        return app(NoticeManager::class)->send(
            $scene,
            [NoticeTarget::email($to, $channel)],
            $parameters,
            $locale,
            $action,
            $receiver,
        );
    }

    /**
     * @param array<mixed> $parameters
     * @param array<mixed>|null $action
     */
    public static function sendInApp(
        string $scene,
        NoticeReceiver $receiver,
        array $parameters = [],
        ?string $channel = null,
        string $locale = 'zh-CN',
        ?array $action = null,
    ): NoticeSendResult {
        return app(NoticeManager::class)->send(
            $scene,
            [NoticeTarget::inApp($receiver, $channel)],
            $parameters,
            $locale,
            $action,
            $receiver,
        );
    }

    /**
     * @param list<NoticeTarget> $targets
     * @param array<mixed> $parameters
     * @param array<mixed>|null $action
     */
    public static function sendMany(
        string $scene,
        array $targets,
        array $parameters = [],
        string $locale = 'zh-CN',
        ?array $action = null,
        ?NoticeReceiver $receiver = null,
    ): NoticeSendResult {
        return app(NoticeManager::class)->send($scene, $targets, $parameters, $locale, $action, $receiver);
    }
}
