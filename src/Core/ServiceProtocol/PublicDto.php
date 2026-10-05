<?php

declare(strict_types=1);

namespace PTAdmin\Core\ServiceProtocol;

/** 公共协议 JSON 使用 snake_case；敏感对象应单独实现受控输出。 */
trait PublicDto
{
    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        $result = [];
        foreach (get_object_vars($this) as $name => $value) {
            $key = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
            $result[$key] = $value instanceof \DateTimeInterface ? $value->format(DATE_RFC3339_EXTENDED) : $value;
        }
        return $result;
    }
}
