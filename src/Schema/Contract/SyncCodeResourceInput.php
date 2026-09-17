<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * SyncCodeResourceInput 描述 code resource 入库同步输入。
 */
final class SyncCodeResourceInput extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'schema' => \PTAdmin\Schema\Definition\Schema::class,
        'table_name' => 'string',
        'migration_owner' => 'string',
        'adopt_existing_table' => 'bool',
        'owner_type' => 'string',
        'owner_code' => 'string',
        'summary' => 'array',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'table_name' => ['omit_empty' => true],
        'migration_owner' => ['omit_empty' => true],
        'adopt_existing_table' => ['omit_empty' => true],
        'owner_type' => ['omit_empty' => true],
        'owner_code' => ['omit_empty' => true],
        'summary' => ['omit_empty' => true],
    ];

    /** Resource 目标资源引用。 */
    public ?ResourceKey $resource = null;

    /** Schema 待同步的 schema 真相。 */
    public ?\PTAdmin\Schema\Definition\Schema $schema = null;

    /** TableName 指定代码资源使用的物理表名；为空时按资源键推导。 */
    public string $table_name = '';

    /** MigrationOwner 指定 Schema 迁移计划的执行方，空值由 Schema 执行。 */
    public string $migration_owner = '';

    /** AdoptExistingTable 表示首次接管已经由领域迁移创建的物理表。 */
    public bool $adopt_existing_table = false;

    /** OwnerType identifies the code resource owner, such as app or addon. */
    public string $owner_type = '';

    /** OwnerCode identifies the concrete application or addon. */
    public string $owner_code = '';

    /** Summary 调用方附带的摘要信息。 */
    public array $summary = [];
}
