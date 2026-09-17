<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ExportFileResult 描述导出文件结果。
 */
final class ExportFileResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'format' => 'string',
        'filename' => 'string',
        'content_type' => 'string',
        'content' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Format 导出内容使用的文件格式。 */
    public string $format = '';

    /** Filename 导入来源或导出结果使用的文件名。 */
    public string $filename = '';

    /** ContentType 下载响应使用的媒体类型。 */
    public string $content_type = '';

    /** Content 已生成的导出文件二进制内容。 */
    public string $content = '';
}
