<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

/** 官方目录使用完整 SemVer，不能以字符串或仅支持固定预发布名的比较器排序。 */
final class ReleaseVersion
{
    public static function valid(mixed $version): bool
    {
        return is_string($version) && strlen($version) <= 100 && preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[a-zA-Z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[a-zA-Z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D', $version) === 1;
    }

    public static function stable(string $version): bool
    {
        return !str_contains(explode('+', $version, 2)[0], '-');
    }

    public static function compare(string $left, string $right): int
    {
        $left = explode('-', explode('+', $left, 2)[0], 2);
        $right = explode('-', explode('+', $right, 2)[0], 2);
        $numeric = static fn (string $a, string $b): int => (strlen($a) <=> strlen($b)) ?: strcmp($a, $b);
        $rightCore = explode('.', $right[0]);
        foreach (explode('.', $left[0]) as $index => $part) {
            if ($difference = $numeric($part, $rightCore[$index])) {
                return $difference;
            }
        }
        if (!isset($left[1]) || !isset($right[1])) {
            return (int) isset($right[1]) <=> (int) isset($left[1]);
        }
        $leftPre = explode('.', $left[1]);
        $rightPre = explode('.', $right[1]);
        foreach ($leftPre as $index => $part) {
            if (!isset($rightPre[$index])) {
                return 1;
            }
            $other = $rightPre[$index];
            $difference = ctype_digit($part) && ctype_digit($other) ? $numeric($part, $other)
                : (((int) ctype_digit($other) <=> (int) ctype_digit($part)) ?: strcmp($part, $other));
            if ($difference !== 0) {
                return $difference;
            }
        }
        return count($leftPre) <=> count($rightPre);
    }
}
