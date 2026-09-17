<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ExportRecordsQuery 描述记录导出输入。
 */
final class ExportRecordsQuery extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'filters' => [Filter::class],
        'sorts' => [Sort::class],
        'scene' => 'string',
        'filename' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'filters' => ['omit_empty' => true],
        'sorts' => ['omit_empty' => true],
        'scene' => ['omit_empty' => true],
        'filename' => ['omit_empty' => true],
    ];

    /** Resource 当前操作所属的资源引用。 */
    public ?ResourceKey $resource = null;

    /** Filters 导出记录使用的字段过滤条件。 */
    public array $filters = [];

    /** Sorts 导出记录使用的稳定排序条件。 */
    public array $sorts = [];

    /** Scene 宿主传入的调用场景标识。 */
    public string $scene = '';

    /** Filename 导入来源或导出结果使用的文件名。 */
    public string $filename = '';
}
