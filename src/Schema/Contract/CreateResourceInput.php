<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * CreateResourceInput 描述创建资源主体时的输入。
 */
final class CreateResourceInput extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'namespace' => 'string',
        'name' => 'string',
        'title' => 'string',
        'description' => 'string',
        'source_type' => 'string',
        'owner_type' => 'string',
        'owner_code' => 'string',
        'design_flow_id' => 'string',
        'table_name' => 'string',
        'migration_owner' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'description' => ['omit_empty' => true],
        'design_flow_id' => ['omit_empty' => true],
        'table_name' => ['serialized' => false],
        'migration_owner' => ['serialized' => false],
    ];

    /** Namespace 资源所属命名空间，用于资源分组和构造稳定引用；允许为空，非空时必须以小写字母开头，只能包含小写字母、数字、下划线，最大长度 32。 */
    public string $namespace = '';

    /** Name 资源名称，用于标识命名空间下的具体资源；创建时必填，必须以小写字母开头，只能包含小写字母、数字、下划线，最大长度 48。 */
    public string $name = '';

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

    /** DesignFlowID 记录创建资源时选择的稳定设计流程；空值表示使用宿主通用设计流程。 */
    public string $design_flow_id = '';

    /** TableName 指定代码资源使用的物理表名；为空时按 namespace 和 name 推导。 */
    public string $table_name = '';

    /** MigrationOwner 只决定由谁执行 Schema 迁移计划；HTTP 创建默认由 Schema 执行。 */
    public string $migration_owner = '';
}
