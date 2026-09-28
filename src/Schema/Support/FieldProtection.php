<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/** Schema Runtime 与配置值共用的敏感字段存储和输出规则。 */
final class FieldProtection
{
    public static function mode(array $field): string
    {
        $extensions = (array) ($field['extensions'] ?? []);
        $secret = (array) ($extensions['secret'] ?? []);

        return strtolower((string) ($secret['storage_mode'] ?? ''));
    }

    public static function hidden(array $field): bool
    {
        $extensions = (array) ($field['extensions'] ?? []);
        $secret = (array) ($extensions['secret'] ?? []);

        return $secret !== [] && (empty($secret['readable']) || in_array(self::mode($field), ['hash', 'hashed'], true));
    }

    public static function store(mixed $value, array $field): mixed
    {
        if ($value === null) {
            return null;
        }

        return match (self::mode($field)) {
            'encrypted' => Crypt::encryptString($value),
            'hash', 'hashed' => hash('sha256', (string) $value),
            default => $value,
        };
    }

    /** 仅供受信任的服务端代码使用；调用方负责授权，不作为 HTTP 输出接口。 */
    public static function reveal(mixed $value, array $field): mixed
    {
        if ($value === null || self::mode($field) !== 'encrypted') {
            return $value;
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            throw new DecryptException('敏感字段解密失败：'.$field['name'].'。请检查应用密钥和存储数据。');
        }
    }

    public static function output(mixed $value, array $field): mixed
    {
        if (self::hidden($field)) {
            return null;
        }
        $value = self::reveal($value, $field);
        $extensions = (array) ($field['extensions'] ?? []);
        if ($value === null || !isset($extensions['mask'])) {
            return $value;
        }
        $mask = (array) $extensions['mask'];
        $raw = (string) $value;
        $type = strtolower((string) ($mask['type'] ?? ''));
        if ($type === 'custom') {
            return $mask['pattern'] ?? '****';
        }
        if ($type === 'email') {
            $parts = explode('@', $raw, 2);
            return count($parts) === 2 ? substr($parts[0], 0, 1).str_repeat('*', max(1, strlen($parts[0]) - 1)).'@'.$parts[1] : '****';
        }
        if ($type === 'name') {
            return $raw === '' ? $raw : mb_substr($raw, 0, 1).str_repeat('*', max(0, mb_strlen($raw) - 1));
        }
        $keepLeft = $type === 'phone' || $type === 'id_card' ? 3 : 1;
        $keepRight = $type === 'phone' || $type === 'id_card' ? 4 : 1;
        $length = mb_strlen($raw);

        return $length <= $keepLeft + $keepRight ? str_repeat('*', $length) : mb_substr($raw, 0, $keepLeft).str_repeat('*', $length - $keepLeft - $keepRight).mb_substr($raw, -$keepRight);
    }
}
