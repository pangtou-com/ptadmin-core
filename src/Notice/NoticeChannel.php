<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

final class NoticeChannel
{
    public const IN_APP_CODE = 'ptadmin.in_app';

    public static function register(string $type, string $title): NoticeChannelDefinitionBuilder
    {
        return app(NoticeRegistry::class)->channel($type, $title);
    }

    public static function registerSMS(string $title): NoticeChannelDefinitionBuilder
    {
        return self::register('sms', $title);
    }

    public static function registerEmail(string $title): NoticeChannelDefinitionBuilder
    {
        return self::register('email', $title);
    }

    public static function registerInApp(string $title): NoticeChannelDefinitionBuilder
    {
        return self::register('in_app', $title);
    }
}
