<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ListResourcesQuery 描述资源列表查询条件。
 */
final class ListResourcesQuery extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'keyword' => 'string',
        'namespace' => 'string',
        'source_type' => 'string',
        'owner_type' => 'string',
        'owner_code' => 'string',
        'status' => 'string',
        'page' => 'int',
        'limit' => 'int',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'keyword' => ['omit_empty' => true],
        'namespace' => ['omit_empty' => true],
        'source_type' => ['omit_empty' => true],
        'owner_type' => ['omit_empty' => true],
        'owner_code' => ['omit_empty' => true],
        'status' => ['omit_empty' => true],
        'page' => ['omit_empty' => true],
        'limit' => ['omit_empty' => true],
    ];

    /** Keyword 资源关键字，会匹配资源键、名称、表名和标题。 */
    public string $keyword = '';

    /** Namespace 资源命名空间筛选，匹配资源所属分组；取值规则与创建时一致。 */
    public string $namespace = '';

    /** SourceType 资源来源类型筛选，用于按资源进入 schema 系统的来源渠道过滤，例如 manual、code、plugin、studio。 */
    public string $source_type = '';

    /** OwnerType 资源归属主体类型筛选，用于按资源归谁管理或交付过滤，例如 app、builtin、plugin。 */
    public string $owner_type = '';

    /** OwnerCode 资源归属主体编码筛选，用于按 OwnerType 下的具体主体实例过滤，例如 demo、cms、feature.cms。 */
    public string $owner_code = '';

    /** Status 资源主体状态。 */
    public string $status = '';

    /** Page 页码，从 1 开始。 */
    public int $page = 0;

    /** Limit 每页条数。 */
    public int $limit = 0;
}
