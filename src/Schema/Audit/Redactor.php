<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Audit;

use PTAdmin\Schema\Definition\Schema;

/**
 * 按通用敏感字段名和 Schema 扩展标记递归清理审计载荷，避免令牌、密码等写入审计表。
 */
final class Redactor
{
    private const GENERIC = ['access_token', 'api_key', 'authorization', 'client_secret', 'credential', 'credentials', 'passwd', 'password', 'pwd', 'refresh_token', 'secret', 'token'];

    public static function redact(array $payload, ?Schema $schema = null): array
    {
        $secret = array_fill_keys(self::GENERIC, true);
        if ($schema) {
            foreach ((array) $schema->fields as $field) {
                if (!empty(($field['extensions'] ?? [])['secret'])) {
                    $secret[strtolower((string) ($field['name'] ?? ''))] = true;
                }
            }
        }
        return self::value($payload, $secret);
    }

    private static function value(mixed $value, array $secret): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = isset($secret[strtolower(trim((string) $key))]) ? '[REDACTED]' : self::value($item, $secret);
            }
            return $result;
        }
        return $value;
    }
}
