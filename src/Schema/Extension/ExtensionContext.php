<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Extension;

use PTAdmin\Schema\Meta\Models\Resource;

/** 插件校验读取当前候选版本和已发布版本，不修改草稿。 */
final class ExtensionContext
{
    /** @param array<string,mixed> $schema @param array<string,mixed>|null $activeSchema */
    public function __construct(
        public readonly Resource $resource,
        public readonly array $schema,
        public readonly ?array $activeSchema,
    ) {
    }
}
