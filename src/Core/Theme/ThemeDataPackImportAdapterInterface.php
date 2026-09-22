<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

interface ThemeDataPackImportAdapterInterface
{
    /** Return the resource contract when this adapter owns the resource. */
    public function resource(string $resourceKey): ?ThemeDataPackResource;

    /**
     * Import validated records without overwriting existing business data.
     *
     * @param list<array{key:string,data:array<string,mixed>}> $records
     */
    public function import(string $resourceKey, array $records): ThemeDataPackImportResult;
}
