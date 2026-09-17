<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Audit\Models;

use PTAdmin\Schema\Support\DataObject;

/**
 * 审计事件的只读载荷对象。payload 字段已在写入前脱敏，调用方不应把它当作原始业务数据使用。
 */
final class Event extends DataObject
{
}
