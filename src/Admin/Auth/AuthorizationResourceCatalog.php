<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Auth;

final class AuthorizationResourceCatalog
{
    /** @param array<string, mixed> $resource @return array{abilities:list<string>,scopes:list<string>} */
    public function capabilities(array $resource): array
    {
        $meta = $this->decode($resource['meta_json'] ?? null);
        if (($resource['type'] ?? null) === 'group') {
            return ['abilities' => [], 'scopes' => []];
        }

        return [
            'abilities' => $this->stringList($meta['abilities'] ?? []),
            'scopes' => $this->stringList($meta['scopes'] ?? []),
        ];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? $item : '', $value),
            static fn (string $item): bool => $item !== '',
        )));
    }

    /** @return array<string, mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
