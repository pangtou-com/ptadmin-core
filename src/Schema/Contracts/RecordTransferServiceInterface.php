<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contracts;

/**
 * 导入导出适配器边界。具体文件格式和大文件处理由宿主注入，Schema 包只负责协议载荷转发。
 */
interface RecordTransferServiceInterface
{
    /** 生成导入模板。 */
    public function exportTemplate(array|object $query): mixed;
    /** 导出资源记录。 */
    public function exportRecords(array|object $query): mixed;
    /** 校验并预览导入内容。 */
    public function checkImport(array|object $input): mixed;
    /** 更新导入列映射。 */
    public function updateImportMapping(array|object $input): mixed;
    /** 提交已校验的导入批次。 */
    public function commitImport(array|object $input): mixed;
}
