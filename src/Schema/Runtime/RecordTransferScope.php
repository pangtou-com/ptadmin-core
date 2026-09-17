<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use PTAdmin\Schema\Contracts\RecordTransferServiceInterface;
use PTAdmin\Schema\Support\ResourceBoundScope;

/**
 * 绑定单个资源的导入导出作用域，具体文件格式仍由宿主适配器处理。
 */
final class RecordTransferScope extends ResourceBoundScope
{
    public function __construct(string $resource, private RecordTransferServiceInterface $transfers)
    {
        parent::__construct($resource);
    }

    /** 导出当前资源的导入模板。 */
    public function exportTemplate(array|object $query = []): mixed
    {
        return $this->transfers->exportTemplate($this->resourcePayload($query));
    }

    /** 导出当前资源记录。 */
    public function exportRecords(array|object $query = []): mixed
    {
        return $this->transfers->exportRecords($this->resourcePayload($query));
    }

    /** 检查当前资源的导入文件并建立会话。 */
    public function checkImport(array|object $input): mixed
    {
        return $this->transfers->checkImport($this->resourcePayload($input));
    }

    /** 更新导入会话列映射；会话所属资源由适配器校验。 */
    public function updateImportMapping(array|object $input): mixed
    {
        return $this->transfers->updateImportMapping($input);
    }

    /** 提交导入会话；会话所属资源由适配器校验。 */
    public function commitImport(array|object $input): mixed
    {
        return $this->transfers->commitImport($input);
    }
}
