<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tasks;

final class TaskRedactor
{
    /** @param array<string, mixed> $value @return array<string, mixed> */
    public static function array(array $value): array
    {
        $redacted = self::value($value);
        return is_array($redacted) ? $redacted : [];
    }

    /** @param mixed $value */
    public static function value(mixed $value, ?string $key = null): mixed
    {
        if (self::sensitiveKey($key)) {
            return '[redacted]';
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $childKey => $childValue) {
                $result[$childKey] = self::value($childValue, (string) $childKey);
            }
            return $result;
        }
        return is_string($value) ? self::message($value) : $value;
    }

    public static function message(string $message): string
    {
        return preg_replace('/\b(password|passwd|token|secret|api[_-]?key)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $message) ?? $message;
    }

    private static function sensitiveKey(?string $key): bool
    {
        return null !== $key && preg_match('/(?:password|passwd|token|secret|api[_-]?key|authorization)/i', $key) === 1;
    }
}
