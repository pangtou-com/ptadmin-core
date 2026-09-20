<?php

declare(strict_types=1);

namespace PTAdmin\Core\Dictionary\Contracts;

use PTAdmin\Core\Dictionary\DictionaryRegistry;

/** 通过 PHP 链式 API 声明一组系统或插件字典。 */
interface DictionaryProviderInterface
{
    public function register(DictionaryRegistry $registry): void;
}
