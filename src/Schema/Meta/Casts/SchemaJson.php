<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Meta\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use PTAdmin\Schema\Support\Protocol;

/** Schema 正文继续使用协议数组，资源级扩展保留任意 JSON 对象形状。 */
final class SchemaJson implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('Stored Schema must contain an object definition.');
        }
        if (array_key_exists('extensions', $decoded)) {
            $decoded['extensions'] = Protocol::decode($value)['extensions'];
        }
        return $decoded;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
