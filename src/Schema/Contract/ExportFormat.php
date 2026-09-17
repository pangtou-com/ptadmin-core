<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * 记录导出适配器返回的文件格式。
 *
 * 当前协议只约定 XLSX；具体文件编解码仍由宿主注入的适配器实现。
 */
final class ExportFormat
{
    public const XLSX = 'xlsx';
}
