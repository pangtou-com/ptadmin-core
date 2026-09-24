<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

final class NoticeReceiver
{
    public function __construct(
        public readonly string $type,
        public readonly int $id,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,19}$/', $type) !== 1 || $id < 1) {
            throw new NoticeSendingException('站内信接收主体无效。', 'notice_receiver_invalid');
        }
    }

    public static function admin(int $id): self
    {
        return new self('admin', $id);
    }

    public function key(): string
    {
        return $this->type.':'.$this->id;
    }
}
