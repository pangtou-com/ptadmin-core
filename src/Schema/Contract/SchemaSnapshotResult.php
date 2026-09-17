<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * SchemaSnapshotResult 描述运行时 schema 快照结果。
 */
final class SchemaSnapshotResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'version' => VersionInfo::class,
        'features' => 'array',
        'capabilities' => 'array',
        'state' => 'array',
        'tree' => 'array',
        'fields' => 'array',
        'actions' => 'array',
        'meta' => 'array',
        'views' => 'array',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'features' => ['omit_empty' => true],
        'capabilities' => ['omit_empty' => true],
        'state' => ['nullable' => true, 'omit_empty' => true],
        'tree' => ['nullable' => true, 'omit_empty' => true],
        'fields' => ['omit_empty' => true],
        'actions' => ['omit_empty' => true],
        'meta' => ['omit_empty' => true],
        'views' => ['omit_empty' => true],
    ];

    /** Resource 所属资源引用。 */
    public ?ResourceKey $resource = null;

    /** Version 当前激活版本信息。 */
    public ?VersionInfo $version = null;

    /** Features 当前启用的特性集合。 */
    public array $features = [];

    /** Capabilities 当前能力开关集合。 */
    public array $capabilities = [];

    /** State 当前业务状态配置。 */
    public ?array $state = null;

    /** Tree 当前树形能力配置。 */
    public ?array $tree = null;

    /** Fields 运行时字段定义集合。 */
    public array $fields = [];

    /** Actions 可用动作定义集合。 */
    public array $actions = [];

    /** Meta 附加元信息。 */
    public array $meta = [];

    /** Views UI 场景视图定义集合。 */
    public array $views = [];
}
