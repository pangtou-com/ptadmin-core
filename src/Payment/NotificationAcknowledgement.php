<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

use PTAdmin\Core\ServiceProtocol\{Input, ProtocolException};

/** 原样交给 HTTP 响应，不套后台 API 的 code/message/data。 */
final class NotificationAcknowledgement
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
        if ($status < 100 || $status > 599) {
            throw new ProtocolException('result_invalid', '厂商通知确认的 HTTP 状态无效。');
        }
        Input::stringMap($headers, 'acknowledgement_headers');
        foreach ($headers as $name => $value) {
            if (preg_match('/[\r\n]/', $name.$value)) {
                throw new ProtocolException('result_invalid', '厂商通知确认包含无效响应头。');
            }
        }
    }
}
