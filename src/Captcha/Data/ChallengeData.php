<?php

declare(strict_types=1);

namespace PTAdmin\Captcha\Data;

use InvalidArgumentException;
use JsonSerializable;

/** 挑战协议的不可变数据；未声明的字段不能混入输出。 */
abstract class ChallengeData implements JsonSerializable
{
    protected readonly array $attributes;

    final public function __construct(array $attributes = [])
    {
        $this->attributes = static::normalize($attributes);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    abstract protected static function defaults(): array;

    protected static function normalize(array $data): array
    {
        if (array_diff_key($data, static::defaults()) !== []) {
            throw new InvalidArgumentException('验证码协议包含未声明字段。');
        }
        return array_replace(static::defaults(), $data);
    }
}
