<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contracts;

use PTAdmin\Schema\Meta\Models\Resource;
use PTAdmin\Schema\Definition\Schema;

interface SchemaExtensionWriterInterface
{
    /** 在 Runtime 写入事务中同步插件投影；异常导致本次写入回滚。 */
    public function written(Resource $resource, Schema $schema, int $recordId): void;
}
