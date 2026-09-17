<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * GetResourceResult 描述资源详情查询结果。
 */
final class GetResourceResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceInfo::class,
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Resource 资源主体信息。 */
    public ?ResourceInfo $resource = null;
}
