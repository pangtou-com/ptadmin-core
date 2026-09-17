<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * 只允许可信宿主注入的受限数据范围条件，不接受前端 JSON 直接构造 SQL。
 */
final class ServerConstraint extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'all' => [self::class],
        'any' => [self::class],
        'field' => 'string',
        'operator' => 'string',
        'value' => 'mixed',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'all' => ['omit_empty' => true],
        'any' => ['omit_empty' => true],
        'field' => ['omit_empty' => true],
        'operator' => ['omit_empty' => true],
    ];

    /** 组合约束，所有子条件必须同时满足。 */
    public array $all = [];

    /** 组合约束，任一子条件满足即可。 */
    public array $any = [];

    /** 叶子条件字段名，由 Runtime 按 Schema 持久化字段白名单校验。 */
    public string $field = '';

    /** 叶子条件操作符，当前协议只支持 eq 和 in。 */
    public string $operator = '';

    /** 叶子条件值，不参与 HTTP JSON 协议序列化。 */
    public mixed $value = null;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $value = parent::toArray();

        foreach (['all', 'any'] as $key) {
            if (isset($value[$key]) && is_array($value[$key])) {
                $value[$key] = array_map(
                    static fn ($item): array => $item instanceof self ? $item->toArray() : (array) $item,
                    $value[$key],
                );
            }
        }

        return $value;
    }
}
