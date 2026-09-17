<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ExportTemplateQuery 描述导出模板输入。
 */
final class ExportTemplateQuery extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'filename' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'filename' => ['omit_empty' => true],
    ];

    /** Resource 当前操作所属的资源引用。 */
    public ?ResourceKey $resource = null;

    /** Filename 导入来源或导出结果使用的文件名。 */
    public string $filename = '';
}
