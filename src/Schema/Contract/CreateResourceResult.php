<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * CreateResourceResult 描述创建资源后的结果。
 */
final class CreateResourceResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceInfo::class,
        'draft_version' => VersionInfo::class,
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Resource 新建后的资源主体信息。 */
    public ?ResourceInfo $resource = null;

    /** DraftVersion 创建时自动生成的草稿版本。 */
    public ?VersionInfo $draft_version = null;
}
