<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use PTAdmin\Schema\Contract\CheckImportInput;
use PTAdmin\Schema\Contract\CommitImportInput;
use PTAdmin\Schema\Contract\ExportRecordsQuery;
use PTAdmin\Schema\Contract\ExportTemplateQuery;
use PTAdmin\Schema\Contract\SchemaError;
use PTAdmin\Schema\Contract\UpdateImportMappingInput;
use PTAdmin\Schema\Support\Payload;

/**
 * 记录传输协议的宿主边界。文件格式实现由宿主注入，当前包不包含 XLSX 依赖。
 */
final class RecordTransferService implements \PTAdmin\Schema\Contracts\RecordTransferServiceInterface
{
    /** @var object|null */
    private $adapter;

    /**
     * @param object|null $adapter 宿主导入导出适配器；包本身不负责具体文件格式。
     */
    public function __construct(?object $adapter = null)
    {
        $this->adapter = $adapter;
    }
    /** 将模板请求转交宿主适配器。 */
    public function exportTemplate(array|object $query): mixed
    {
        return $this->call('exportTemplate', Payload::dto($query, ExportTemplateQuery::class));
    }
    /** 将记录导出请求转交宿主适配器。 */
    public function exportRecords(array|object $query): mixed
    {
        return $this->call('exportRecords', Payload::dto($query, ExportRecordsQuery::class));
    }
    /** 将导入校验请求转交宿主适配器。 */
    public function checkImport(array|object $input): mixed
    {
        return $this->call('checkImport', Payload::dto($input, CheckImportInput::class));
    }
    /** 将列映射更新请求转交宿主适配器。 */
    public function updateImportMapping(array|object $input): mixed
    {
        return $this->call('updateImportMapping', Payload::dto($input, UpdateImportMappingInput::class));
    }
    /** 将导入提交请求转交宿主适配器。 */
    public function commitImport(array|object $input): mixed
    {
        return $this->call('commitImport', Payload::dto($input, CommitImportInput::class));
    }
    private function call(string $method, mixed $payload): mixed
    {
        if ($this->adapter !== null && method_exists($this->adapter, $method)) {
            return $this->adapter->{$method}($payload);
        }
        throw new SchemaError('action_not_available', 'record transfer adapter is not configured', [], [], false, 'runtime');
    }
}
