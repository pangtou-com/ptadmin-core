<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ImportColumn 描述源文件中的一个列。
 */
final class ImportColumn extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'index' => 'int',
        'name' => 'string',
        'matched_field' => 'string',
        'match_by' => 'string',
        'required' => 'bool',
        'ignored' => 'bool',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'matched_field' => ['omit_empty' => true],
        'match_by' => ['omit_empty' => true],
        'required' => ['omit_empty' => true],
        'ignored' => ['omit_empty' => true],
    ];

    /** Index 来源列从零开始的位置。 */
    public int $index = 0;

    /** Name 字段或列的稳定名称。 */
    public string $name = '';

    /** MatchedField 自动或人工匹配到的 Schema 字段。 */
    public string $matched_field = '';

    /** MatchBy 本次字段匹配采用的名称、别名或人工策略。 */
    public string $match_by = '';

    /** Required 表示映射字段缺失时是否阻止导入提交。 */
    public bool $required = false;

    /** Ignored 表示当前来源列不会写入业务记录。 */
    public bool $ignored = false;
}
