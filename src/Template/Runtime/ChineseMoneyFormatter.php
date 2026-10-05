<?php

declare(strict_types=1);

namespace PTAdmin\Template\Runtime;

use InvalidArgumentException;

/** 以十进制字符串进位，避免大金额先转浮点丢失分位。 */
final class ChineseMoneyFormatter
{
    private const DIGITS = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'];

    public function format(mixed $amount): string
    {
        if ((!is_int($amount) && !is_float($amount) && !is_string($amount))
            || !preg_match('/^(-?)([0-9]+|[0-9]{1,3}(?:,[0-9]{3})+)(?:\.([0-9]+))?$/D', trim((string) $amount), $match)) {
            throw new InvalidArgumentException('金额必须是十进制数值。');
        }
        $integer = ltrim(str_replace(',', '', $match[2]), '0') ?: '0';
        $decimal = str_pad($match[3] ?? '', 3, '0');
        $cents = $integer.substr($decimal, 0, 2);
        if ((int) $decimal[2] >= 5) {
            $cents = $this->increment($cents);
        }
        $integer = substr($cents, 0, -2);
        if (strlen($integer) > 16) {
            throw new InvalidArgumentException('金额整数部分最多支持 16 位。');
        }
        $jiao = (int) $cents[-2];
        $fen = (int) $cents[-1];
        $result = $this->integer($integer).'元';
        if ($jiao === 0 && $fen === 0) {
            $result .= '整';
        } else {
            if ($jiao > 0) {
                $result .= self::DIGITS[$jiao].'角';
            } elseif ($integer !== '0') {
                $result .= '零';
            }
            if ($fen > 0) {
                $result .= self::DIGITS[$fen].'分';
            }
        }
        return $match[1] === '-' && ($integer !== '0' || $jiao !== 0 || $fen !== 0) ? '负'.$result : $result;
    }

    private function increment(string $digits): string
    {
        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string) ((int) $digits[$index] + 1);
                return $digits;
            }
            $digits[$index] = '0';
        }
        return '1'.$digits;
    }

    private function integer(string $integer): string
    {
        if ($integer === '0') {
            return self::DIGITS[0];
        }
        $sections = str_split(str_pad($integer, (int) (ceil(strlen($integer) / 4) * 4), '0', STR_PAD_LEFT), 4);
        $units = ['', '万', '亿', '兆'];
        $result = '';
        $pendingZero = false;
        foreach ($sections as $index => $section) {
            if ((int) $section === 0) {
                $pendingZero = $result !== '';
                continue;
            }
            if ($result !== '' && ($pendingZero || (int) $section < 1000)) {
                $result .= '零';
            }
            $result .= $this->section($section).$units[count($sections) - $index - 1];
            $pendingZero = false;
        }
        return $result;
    }

    private function section(string $section): string
    {
        $units = ['仟', '佰', '拾', ''];
        $result = '';
        $pendingZero = false;
        for ($index = 0; $index < 4; $index++) {
            $digit = (int) $section[$index];
            if ($digit === 0) {
                $pendingZero = $result !== '';
                continue;
            }
            $result .= ($pendingZero ? '零' : '').self::DIGITS[$digit].$units[$index];
            $pendingZero = false;
        }
        return $result;
    }
}
