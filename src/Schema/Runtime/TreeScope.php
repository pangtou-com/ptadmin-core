<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use PTAdmin\Schema\Contracts\TreeServiceInterface;
use PTAdmin\Schema\Support\ResourceBoundScope;

/**
 * 绑定单个资源的树查询、移动和重建作用域。
 */
final class TreeScope extends ResourceBoundScope
{
    public function __construct(string $resource, private TreeServiceInterface $trees)
    {
        parent::__construct($resource);
    }

    /** 查询当前资源根节点。 */
    public function listRoots(array|object $query = []): array
    {
        return $this->trees->listRoots($this->resourcePayload($query));
    }

    /** 查询当前资源指定父节点的直接子节点。 */
    public function listChildren(array|object $query): array
    {
        return $this->trees->listChildren($this->resourcePayload($query));
    }

    /** 查询当前资源子树。 */
    public function getSubtree(array|object $query): array
    {
        return $this->trees->getSubtree($this->resourcePayload($query));
    }

    /** 查询当前资源节点祖先链。 */
    public function getAncestors(array|object $query): array
    {
        return $this->trees->getAncestors($this->resourcePayload($query));
    }

    /** 移动当前资源中的树节点。 */
    public function moveNode(array|object $input): array
    {
        return $this->trees->moveNode($this->resourcePayload($input));
    }

    /** 返回当前资源嵌套树。 */
    public function getTree(array|object $query = []): array
    {
        return $this->trees->getTree($this->resourcePayload($query));
    }

    /** 校验并重建当前资源树结构。 */
    public function rebuildTree(): array
    {
        return $this->trees->rebuildTree($this->resourceKey());
    }
}
