<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * CandidateItem 描述一个关联候选项。
 */
final class CandidateItem extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'value' => 'mixed',
        'label' => 'string',
        'disabled' => 'bool',
        'description' => 'string',
        'group' => CandidateGroup::class,
        'parent_value' => 'mixed',
        'has_children' => 'bool',
        'path_labels' => 'array',
        'meta' => 'array',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'disabled' => ['omit_empty' => true],
        'description' => ['omit_empty' => true],
        'group' => ['nullable' => true, 'omit_empty' => true],
        'parent_value' => ['omit_empty' => true],
        'has_children' => ['omit_empty' => true],
        'path_labels' => ['omit_empty' => true],
        'meta' => ['omit_empty' => true],
    ];

    /** Value 候选值。 */
    public mixed $value = null;

    /** Label 候选项展示文本。 */
    public string $label = '';

    /** Disabled 该候选项当前是否不可选。 */
    public bool $disabled = false;

    /** Description 候选项下拉列表中的补充说明。 */
    public string $description = '';

    /** Group 候选项所属分组；调用端不支持分组时可以忽略。 */
    public ?CandidateGroup $group = null;

    /** ParentValue 树形候选项的父节点值。 */
    public mixed $parent_value = null;

    /** HasChildren 树形候选项是否仍有子节点。 */
    public bool $has_children = false;

    /** PathLabels 树形搜索场景可选的路径标签集合。 */
    public array $path_labels = [];

    /** Meta 候选项附加元信息。 */
    public array $meta = [];
}
