<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contracts;

/**
 * 树资源查询、移动和重建边界。移动操作必须阻止自环和移动到后代节点造成的环路。
 */
interface TreeServiceInterface
{
    /** 查询根节点。 */
    public function listRoots(array|object $query): array;
    /** 查询指定父节点的直接子节点。 */
    public function listChildren(array|object $query): array;
    /** 返回嵌套 children 结构的完整树。 */
    public function getTree(array|object $query): array;
    /** 以扁平列表返回节点及其后代。 */
    public function getSubtree(array|object $query): array;
    /** 返回从根到当前节点的祖先链。 */
    public function getAncestors(array|object $query): array;
    /** 移动节点并校验树结构不会形成环。 */
    public function moveNode(array|object $input): array;
    /** 重建树索引前校验父节点图。 */
    public function rebuildTree(string $resourceKey): array;
}
