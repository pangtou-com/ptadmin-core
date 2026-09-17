<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Audit\Service;

use PTAdmin\Schema\Definition\Schema;
use PTAdmin\Schema\Support\ResourceBoundScope;

/**
 * 绑定单个资源的审计写入和查询作用域。
 */
final class AuditScope extends ResourceBoundScope
{
    public function __construct(string $resource, private AuditWriter $audit)
    {
        parent::__construct($resource);
    }

    /** 写入当前资源审计事件，并覆盖载荷中的 resource_key。 */
    public function write(string $action, array $payload = [], ?Schema $schema = null): void
    {
        $this->audit->write($action, $this->resourcePayload($payload, 'resource_key'), $schema);
    }

    /** 分页查询当前资源审计事件。 */
    public function list(array|object $query = []): array
    {
        return $this->audit->list($this->resourcePayload($query, 'resource_key'));
    }
}
