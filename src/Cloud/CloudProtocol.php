<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

final class CloudProtocol
{
    // 当前联调地址固定在协议入口，正式部署时统一修改。
    public const SITE_URL = 'http://127.0.0.1:8001';
    public const GRANT_LIFETIME_SECONDS = 7200;

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** 授权码不进入签名载荷与日志，签名绑定摘要、应用身份和一次性随机数。 */
    public static function codeDeliveryPayload(array $values): string
    {
        return json_encode(['instance_id' => $values['instance_id'], 'public_key' => $values['public_key'],
            'code_hash' => hash('sha256', strtoupper(trim($values['license_code']))),
            'timestamp' => $values['timestamp'], 'nonce' => $values['nonce']],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** 字段顺序和 JSON 编码是实例签名协议的一部分。 */
    public static function authorizationPayload(array $values): string
    {
        $payload = [];
        $fields = ['instance_id', 'public_key', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'timestamp', 'nonce'];
        if (($values['flow'] ?? 'browser') === 'cli') {
            $fields[] = 'flow';
        }
        foreach ($fields as $field) {
            $payload[$field] = $values[$field];
        }
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
