<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

final class NoticeTarget
{
    private function __construct(
        public readonly ?string $type,
        public readonly ?string $channel,
        public readonly ?string $destination,
        public readonly ?NoticeReceiver $receiver,
    ) {
    }

    public static function channel(string $channel, string $to): self
    {
        return new self(null, strtolower(self::required($channel, 'notice_channel_required')), self::required($to, 'notice_destination_required'), null);
    }

    public static function sms(string $to, ?string $channel = null): self
    {
        return self::external('sms', $to, $channel);
    }

    public static function email(string $to, ?string $channel = null): self
    {
        return self::external('email', $to, $channel);
    }

    public static function inApp(NoticeReceiver $receiver, ?string $channel = null): self
    {
        return new self('in_app', self::optional($channel), null, $receiver);
    }

    private static function external(string $type, string $to, ?string $channel): self
    {
        return new self($type, self::optional($channel), self::required($to, 'notice_destination_required'), null);
    }

    private static function required(string $value, string $errorCode): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new NoticeSendingException('通知目标参数不能为空。', $errorCode);
        }

        return $value;
    }

    private static function optional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = strtolower(trim($value));

        return $value !== '' ? $value : null;
    }
}
