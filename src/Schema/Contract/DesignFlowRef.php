<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * DesignFlowRef 唯一标识宿主可信 Manifest 中的一条设计流程。
 */
final class DesignFlowRef extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'plugin' => 'string',
        'id' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Plugin 设计流程所属的插件编码。 */
    public string $plugin = '';

    /** ID 当前对象的稳定标识。 */
    public string $id = '';
}
