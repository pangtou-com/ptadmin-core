<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

/** 批量 HTTP 回执的输入边界，全部校验通过后才允许写入。 */
final class NoticeReceiptPayload
{
    /** @return list<array<string, mixed>> */
    public static function items(string $body): array
    {
        if (strlen($body) > 262144) {
            throw new NoticeSendingException('回执正文超过 256 KiB。', 'notice_receipt_invalid');
        }
        try {
            $items = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new NoticeSendingException('回执 JSON 无效。', 'notice_receipt_invalid');
        }
        if (!is_array($items) || !array_is_list($items) || $items === [] || count($items) > 1000) {
            throw new NoticeSendingException('回执必须是包含 1 到 1000 条记录的数组。', 'notice_receipt_invalid');
        }
        foreach ($items as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new NoticeSendingException('回执记录必须是 JSON 对象。', 'notice_receipt_invalid');
            }
        }
        return $items;
    }

    /** @param array<string, mixed> $item */
    public static function text(array $item, string $field, int $limit = 255): string
    {
        $value = $item[$field] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $limit || trim($value) !== $value) {
            throw new NoticeSendingException('回执字段无效：'.$field, 'notice_receipt_invalid');
        }
        return $value;
    }

    public static function timestamp(string $value): int
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('Asia/Shanghai'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value
            || $date->getTimestamp() <= 0 || $date->getTimestamp() > 4294967295) {
            throw new NoticeSendingException('回执时间格式无效。', 'notice_receipt_invalid');
        }
        return $date->getTimestamp();
    }
}
