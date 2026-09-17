<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * RelationCandidatesQuery 描述关联候选项查询输入。
 */
final class RelationCandidatesQuery extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'resource' => ResourceKey::class,
        'field' => 'string',
        'keyword' => 'string',
        'page' => 'int',
        'limit' => 'int',
        'values' => 'array',
        'parent_value' => 'mixed',
        'state_kinds' => 'array',
        'params' => 'array',
        'form_values' => 'array',
        'meta' => 'array',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'keyword' => ['omit_empty' => true],
        'page' => ['omit_empty' => true],
        'limit' => ['omit_empty' => true],
        'values' => ['omit_empty' => true],
        'parent_value' => ['omit_empty' => true],
        'state_kinds' => ['omit_empty' => true],
        'params' => ['omit_empty' => true],
        'form_values' => ['omit_empty' => true],
        'meta' => ['omit_empty' => true],
    ];

    /** Resource 目标资源引用。 */
    public ?ResourceKey $resource = null;

    /** Field 关联字段名。 */
    public string $field = '';

    /** Keyword 候选项搜索关键字。 */
    public string $keyword = '';

    /** Page 页码，从 1 开始。 */
    public int $page = 0;

    /** Limit 每页条数。 */
    public int $limit = 0;

    /** Values 需要回显补齐的已选值集合。 */
    public array $values = [];

    /** ParentValue 树形候选项查询的父节点值。 */
    public mixed $parent_value = null;

    /** StateKinds 按关联目标资源 state.values[].kind 过滤候选项；为空时不做状态过滤。 */
    public array $state_kinds = [];

    /** Params 前端或调用方传入的 relation 查询参数。 */
    public array $params = [];

    /** FormValues 当前表单值，用于解析 relation filters[].from。 */
    public array $form_values = [];

    /** Meta 运行时附加辅助参数。 */
    public array $meta = [];
}
