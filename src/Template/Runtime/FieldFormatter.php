<?php

declare(strict_types=1);

namespace PTAdmin\Template\Runtime;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Illuminate\Support\Str;

/** 固定格式化能力；不按模板输入动态调用 PHP 函数。 */
final class FieldFormatter
{
    public function format(mixed $value, array $options, bool $nullOnly = false): mixed
    {
        $this->validate($options);
        if ($value === null || (!$nullOnly && $value === '')) {
            $value = $options['default'] ?? '';
        }
        if ($value === '' || array_diff(array_keys($options), ['default']) === []) {
            return $value;
        }
        if (isset($options['format_date']) || ($options['format_before'] ?? false)) {
            $date = $this->date($value);
            $value = isset($options['format_date']) ? $date->format($options['format_date']) : $this->relative($date);
        } elseif ($options['format_money'] ?? false) {
            $value = (new ChineseMoneyFormatter())->format($value);
        }
        if (!is_scalar($value) && !$value instanceof SafeHtml) {
            throw new InvalidArgumentException('文本格式化只接受标量或 SafeHtml。');
        }
        $value = (string) $value;
        if (isset($options['limit'])) {
            $value = Str::limit($value, $options['limit'], $options['pl'] ?? '...');
        }
        return match ($options['func'] ?? '') {
            'trim' => trim($value),
            'strip_tags' => strip_tags($value),
            default => $value,
        };
    }

    private function validate(array $options): void
    {
        foreach ($options as $name => $value) {
            $valid = match ($name) {
                'default' => is_scalar($value) || $value === null || $value instanceof SafeHtml,
                'limit' => is_int($value) && $value >= 1 && $value <= 10000,
                'pl' => is_string($value) && isset($options['limit']),
                'format_date' => is_string($value) && $value !== '',
                'format_before', 'format_money' => is_bool($value),
                'func' => in_array($value, ['trim', 'strip_tags'], true),
                default => false,
            };
            if (!$valid) {
                throw new InvalidArgumentException('字段格式化参数无效：'.$name);
            }
        }
        if ((int) isset($options['format_date']) + (int) ($options['format_before'] ?? false) + (int) ($options['format_money'] ?? false) > 1) {
            throw new InvalidArgumentException('format_date、format_before、format_money 不能同时启用。');
        }
    }

    private function date(mixed $value): CarbonImmutable
    {
        $timezone = date_default_timezone_get();
        try {
            if ($value instanceof DateTimeInterface) {
                return CarbonImmutable::instance($value)->setTimezone($timezone);
            }
            if (is_int($value) || (is_string($value) && preg_match('/^-?[0-9]+$/D', $value))) {
                return CarbonImmutable::createFromTimestamp($value, $timezone);
            }
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('日期值必须是秒时间戳或日期字符串。');
            }
            $date = CarbonImmutable::parse($value, $timezone)->setTimezone($timezone);
            $errors = CarbonImmutable::getLastErrors();
            if ($errors['warning_count'] > 0 || $errors['error_count'] > 0) {
                throw new InvalidArgumentException('日期值无效。');
            }
            return $date;
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('字段日期格式化失败。', 0, $exception);
        }
    }

    private function relative(CarbonImmutable $date): string
    {
        $difference = CarbonImmutable::now()->getTimestamp() - $date->getTimestamp();
        $seconds = abs($difference);
        foreach ([31536000 => '年', 2592000 => '个月', 604800 => '周', 86400 => '天', 3600 => '小时', 60 => '分钟', 1 => '秒'] as $unit => $label) {
            if ($seconds >= $unit) {
                return intdiv($seconds, $unit).$label.($difference < 0 ? '后' : '前');
            }
        }
        return '刚刚';
    }
}
