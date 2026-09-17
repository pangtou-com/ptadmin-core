<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Definition;

use Carbon\Carbon;

/**
 * 执行协议允许的默认值时间表达式，并按字段类型输出稳定格式；不接受任意代码表达式。
 */
final class ValueExpression
{
    /** @param array<string, mixed> $expression */
    public static function evaluate(array $expression, string $fieldType = 'string', ?Carbon $now = null): mixed
    {
        $type = strtolower(trim((string) ($expression['type'] ?? '')));
        if ($type === 'literal') {
            return $expression['value'] ?? null;
        }
        $date = ($now ?: now())->copy();
        if ($type === 'today' || $type === 'start_of_day') {
            $date->startOfDay();
        } elseif ($type === 'end_of_day') {
            $date->endOfDay();
        } elseif ($type === 'start_of_month') {
            $date->startOfMonth();
        } elseif ($type === 'end_of_month') {
            $date->endOfMonth();
        }
        $offset = (array) ($expression['offset'] ?? []);
        if ($offset !== []) {
            $date->addYears((int) ($offset['years'] ?? 0))
                ->addMonths((int) ($offset['months'] ?? 0))
                ->addWeeks((int) ($offset['weeks'] ?? 0))
                ->addDays((int) ($offset['days'] ?? 0))
                ->addHours((int) ($offset['hours'] ?? 0))
                ->addMinutes((int) ($offset['minutes'] ?? 0))
                ->addSeconds((int) ($offset['seconds'] ?? 0));
        }
        $format = strtolower(trim((string) ($expression['format'] ?? '')));
        if ($format === 'timestamp') {
            return $date->valueOf();
        }
        if ($format === 'unix_seconds') {
            return $date->timestamp;
        }
        if ($format === 'date' || $fieldType === 'date') {
            return $date->format('Y-m-d');
        }
        return $date->format('Y-m-d H:i:s');
    }
}
