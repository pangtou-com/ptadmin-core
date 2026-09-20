<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contracts;

/** Schema Runtime 访问字典候选项与校验规则的稳定边界。 */
interface DictionaryResolverInterface
{
    /**
     * @param array<string, mixed> $query
     * @return array{results:array<int,array<string,mixed>>,selected:array<int,array<string,mixed>>,total:int,page:int,limit:int,has_more:bool}
     */
    public function candidates(string $code, array $query, string $fieldType): array;

    /** 校验字段值是否属于当前启用字典项，同时校验字段与字典值类型是否兼容。 */
    public function assertAllowed(string $code, mixed $value, bool $multiple, string $fieldType): void;
}
