<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ResourceInfo 描述资源主体当前状态信息。
 */
final class ResourceInfo extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'id' => 'int',
        'resource_key' => 'string',
        'namespace' => 'string',
        'name' => 'string',
        'table_name' => 'string',
        'migration_owner' => 'string',
        'title' => 'string',
        'description' => 'string',
        'source_type' => 'string',
        'owner_type' => 'string',
        'owner_code' => 'string',
        'design_flow' => DesignFlowRef::class,
        'status' => 'string',
        'active_version_id' => 'int',
        'active_version' => VersionRef::class,
        'has_unpublished_changes' => 'bool',
        'capabilities' => ResourceCapabilities::class,
        'state' => ResourceState::class,
        'created_at' => 'mixed',
        'updated_at' => 'mixed',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'description' => ['omit_empty' => true],
        'design_flow' => ['nullable' => true, 'omit_empty' => true],
        'active_version_id' => ['omit_empty' => true],
        'active_version' => ['omit_empty' => true],
        'has_unpublished_changes' => ['omit_empty' => true],
        'capabilities' => ['omit_empty' => true],
        'state' => ['nullable' => true, 'omit_empty' => true],
    ];

    /** ID 资源主体内部唯一标识。 */
    public int $id = 0;

    /** ResourceKey 资源稳定键，按 namespace.name 推导；namespace 为空时退化为 name，用于跨模块、跨插件和运行时统一引用，创建后应保持稳定。 */
    public string $resource_key = '';

    /** Namespace 资源命名空间，用于资源分组；非空时满足小写字母开头、仅含小写字母数字下划线、最大长度 32。 */
    public string $namespace = '';

    /** Name 资源名称，用于标识命名空间下的具体资源；满足小写字母开头、仅含小写字母数字下划线、最大长度 48。 */
    public string $name = '';

    /** TableName 资源最终落表名，按 namespace_name 推导；namespace 为空时退化为 name，用于运行时记录存储表定位，创建后应避免修改。 */
    public string $table_name = '';

    /** MigrationOwner 表示 Schema 迁移计划的执行方。 */
    public string $migration_owner = '';

    /** Title 资源展示标题。 */
    public string $title = '';

    /** Description 资源补充说明。 */
    public string $description = '';

    /** SourceType 资源来源类型，用于标识资源最初通过什么渠道进入 schema 系统；例如 manual 表示人工创建，code 表示代码同步，plugin 表示插件装载，studio 表示管理端编辑。 */
    public string $source_type = '';

    /** OwnerType 资源归属主体类型，用于区分资源归谁管理或交付；例如 app 表示宿主应用内置，builtin 表示内核内建，plugin 表示插件提供。 */
    public string $owner_type = '';

    /** OwnerCode 资源归属主体编码，用于标识 OwnerType 下的具体主体实例；例如 app 下可以是 demo，plugin 下可以是 cms、feature.cms。 */
    public string $owner_code = '';

    /** DesignFlow 记录该资源再次进入设计中心时应恢复的可信插件流程。 */
    public ?DesignFlowRef $design_flow = null;

    /** Status 资源主体当前状态。 */
    public string $status = '';

    /** ActiveVersionID 当前激活版本 ID。 */
    public int $active_version_id = 0;

    /** ActiveVersion 当前激活版本引用。 */
    public ?VersionRef $active_version = null;

    /** HasUnpublishedChanges 表示当前草稿内容与激活版本不同，列表可据此提示仍有待发布修改。 */
    public bool $has_unpublished_changes = false;

    /** Capabilities 当前激活版本投影出的能力开关。 */
    public ?ResourceCapabilities $capabilities = null;

    /** State 当前激活版本投影出的状态配置。 */
    public ?ResourceState $state = null;

    /** CreatedAt 资源创建时间。 */
    public mixed $created_at = null;

    /** UpdatedAt 资源最后更新时间。 */
    public mixed $updated_at = null;
}
