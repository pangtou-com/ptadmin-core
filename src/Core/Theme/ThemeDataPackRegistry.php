<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

use LogicException;

final class ThemeDataPackRegistry
{
    /** @var list<ThemeDataPackImportAdapterInterface> */
    private array $adapters = [];

    public function register(ThemeDataPackImportAdapterInterface $adapter): void
    {
        if (in_array($adapter, $this->adapters, true)) {
            return;
        }
        $this->adapters[] = $adapter;
    }

    public function resource(string $resourceKey): ?ThemeDataPackResource
    {
        foreach ($this->adapters as $adapter) {
            $resource = $adapter->resource($resourceKey);
            if ($resource !== null) {
                return $resource;
            }
        }

        return null;
    }

    /** @return array{available:bool,missing:list<string>} */
    public function availability(ThemeDataPackDescriptor $pack): array
    {
        $missing = [];
        foreach ($pack->requires as $resourceKey) {
            if ($this->resource($resourceKey) === null) {
                $missing[] = $resourceKey;
            }
        }

        return ['available' => $missing === [], 'missing' => $missing];
    }

    /**
     * @param list<array{key:string,data:array<string,mixed>}> $records
     */
    public function import(string $resourceKey, array $records): ThemeDataPackImportResult
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->resource($resourceKey) !== null) {
                return $adapter->import($resourceKey, $records);
            }
        }

        throw new LogicException('No theme data pack adapter is registered for resource: '.$resourceKey);
    }
}
