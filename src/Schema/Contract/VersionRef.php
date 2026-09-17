<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * VersionRef 描述资源版本的引用方式。
 */
final class VersionRef extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'version_id' => 'int',
        'kind' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'version_id' => ['omit_empty' => true],
        'kind' => ['omit_empty' => true],
    ];

    /** VersionID 版本内部唯一标识。 */
    public int $version_id = 0;

    /** Kind 按语义引用版本时的类型，例如 draft 或 active。 */
    public string $kind = '';
}
