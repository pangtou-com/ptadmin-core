<?php

declare(strict_types=1);

namespace PTAdmin\Core\Dictionary;

use PTAdmin\Core\Dictionary\Contracts\DictionaryProviderInterface;

/** Core 内置字典声明入口；业务字典可由 Core 版本在此处以链式 API 增加。 */
final class CoreDictionaryProvider implements DictionaryProviderInterface
{
    public function register(DictionaryRegistry $registry): void
    {
        $registry
            ->define('system.status', '通用状态')
            ->desc('用于数字型状态字段的启用和禁用选项。')
            ->valueType('number')
            ->closed()
            ->addItem(1, '启用', ['type' => 'success'], true, 10)
            ->addItem(0, '禁用', ['type' => 'info'], true, 20);
    }
}
