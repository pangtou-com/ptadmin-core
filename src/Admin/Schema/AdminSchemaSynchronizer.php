<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Schema;

use PTAdmin\Schema\Contracts\PublishServiceInterface;
use PTAdmin\Schema\Contracts\ResourceServiceInterface;
use PTAdmin\Schema\Meta\Models\Resource;
use PTAdmin\Schema\Runtime\RuntimeCacheStore;
use PTAdmin\Schema\Runtime\RuntimeRegistry;
use PTAdmin\Schema\Runtime\RuntimeRegistryValidator;

final class AdminSchemaSynchronizer
{
    public function __construct(
        private AdminSchemaDefinitions $definitions,
        private ResourceServiceInterface $resources,
        private PublishServiceInterface $publish,
        private ?RuntimeCacheStore $runtimeCache = null,
        private ?RuntimeRegistry $runtimeRegistry = null,
        private ?RuntimeRegistryValidator $runtimeValidator = null,
    ) {
    }

    /** @return list<string> */
    public function sync(): array
    {
        $synced = [];
        foreach ($this->definitions->all() as $resourceKey => $definition) {
            $result = $this->resources->syncCodeResource([
                'resource' => $resourceKey,
                'schema' => $definition['schema'],
                'table_name' => $definition['table'],
                'migration_owner' => 'domain',
                'adopt_existing_table' => true,
                'owner_type' => 'core',
                'owner_code' => 'ptadmin',
            ]);
            $resource = Resource::query()->where('resource_key', $resourceKey)->firstOrFail();
            $active = $resource->versions()->where('status', 'active')->first();
            $draft = $resource->versions()->where('status', 'draft')->first();
            if (!$draft) {
                $synced[] = $resourceKey;
                continue;
            }
            if (!$active || $active->checksum !== $draft->checksum) {
                $this->publish->checkDraft(['resource' => $resourceKey, 'expected_checksum' => $draft->checksum]);
                $this->runtimeCache?->clear();
                $this->publish->publishDraft(['resource' => $resourceKey, 'expected_checksum' => $draft->checksum]);
            }
            $synced[] = $resourceKey;
        }

        if ($this->runtimeRegistry !== null && $this->runtimeValidator !== null) {
            $this->runtimeValidator->assertValid($this->runtimeRegistry->build(), $synced);
        }

        return $synced;
    }
}
