<?php

declare(strict_types=1);

namespace PTAdmin\Schema\SchemaFile;

use PTAdmin\Schema\Contracts\PublishServiceInterface;
use PTAdmin\Schema\Contracts\ResourceServiceInterface;
use PTAdmin\Schema\Meta\Models\Resource;
use PTAdmin\Schema\Runtime\RuntimeCacheStore;
use PTAdmin\Schema\Runtime\RuntimeRegistry;
use PTAdmin\Schema\Runtime\RuntimeRegistryValidator;
use RuntimeException;

final class SchemaFileSynchronizer
{
    public function __construct(
        private Loader $loader,
        private ResourceServiceInterface $resources,
        private PublishServiceInterface $publish,
        private ?RuntimeCacheStore $runtimeCache = null,
        private ?RuntimeRegistry $runtimeRegistry = null,
        private ?RuntimeRegistryValidator $runtimeValidator = null,
    ) {
    }

    /**
     * @param list<array{path:string,relative_path:string,owner_type:string,owner_code:string,source:string}> $files
     * @return list<array{resource:string,path:string,status:string,risk:string,checksum:string}>
     */
    public function sync(array $files, bool $draftOnly = false, bool $validateRuntime = true): array
    {
        $definitions = $this->loadAll($files);
        $results = [];
        foreach ($definitions as $definition) {
            $resourceKey = $definition['resource'];
            $sync = $this->resources->syncCodeResource([
                'resource' => $resourceKey,
                'schema' => $definition['schema']->toArray(),
                'table_name' => $definition['table_name'],
                'migration_owner' => $definition['migration_owner'],
                'owner_type' => $definition['owner_type'],
                'owner_code' => $definition['owner_code'],
                'summary' => ['path' => $definition['path'], 'source' => $definition['source']],
            ]);
            $checksum = (string) ($sync->version?->checksum ?? '');
            $resource = Resource::query()->where('resource_key', $resourceKey)->firstOrFail();
            $active = $resource->versions()->where('status', 'active')->first();
            if ($active && hash_equals((string) $active->checksum, $checksum)) {
                $results[] = $this->result($definition, 'unchanged', 'safe', $checksum);
                continue;
            }
            if ($draftOnly) {
                $results[] = $this->result($definition, 'draft', 'unchecked', $checksum);
                continue;
            }

            $checked = $this->publish->checkDraft([
                'resource' => $resourceKey,
                'expected_checksum' => $checksum,
            ]);
            $risk = (string) ($checked->migration_plan?->risk ?? 'safe');
            if ($risk !== 'safe') {
                $results[] = $this->result($definition, 'blocked', $risk, $checksum);
                continue;
            }
            $this->runtimeCache?->clear();
            $this->publish->publishDraft([
                'resource' => $resourceKey,
                'expected_checksum' => $checksum,
            ]);
            $results[] = $this->result($definition, 'published', $risk, $checksum);
        }

        if ($validateRuntime && !$draftOnly) {
            $this->validateRuntime(array_map(static fn (array $definition): string => $definition['resource'], $definitions));
        }

        return $results;
    }

    /** @return array{resource:string,status:string,risk:string,checksum:string} */
    public function publish(string $resourceKey, bool $force = false): array
    {
        $resource = Resource::query()->where('resource_key', trim($resourceKey))->first();
        if (!$resource) {
            throw new RuntimeException('Schema resource not found: '.$resourceKey);
        }
        $draft = $resource->versions()->where('status', 'draft')->first();
        if (!$draft) {
            throw new RuntimeException('Schema resource has no draft: '.$resourceKey);
        }
        $checksum = (string) $draft->checksum;
        $checked = $this->publish->checkDraft([
            'resource' => $resourceKey,
            'expected_checksum' => $checksum,
        ]);
        $risk = (string) ($checked->migration_plan?->risk ?? 'safe');
        if ($risk === 'destructive' && !$force) {
            throw new RuntimeException('Destructive Schema changes require --force: '.$resourceKey);
        }
        if ($risk === 'destructive') {
            $this->publish->approveDraft([
                'resource' => $resourceKey,
                'expected_checksum' => $checksum,
                'approved_by' => 'schema:publish',
            ]);
        }
        $this->runtimeCache?->clear();
        $this->publish->publishDraft([
            'resource' => $resourceKey,
            'expected_checksum' => $checksum,
        ]);
        $this->validateRuntime([$resourceKey]);

        return ['resource' => $resourceKey, 'status' => 'published', 'risk' => $risk, 'checksum' => $checksum];
    }

    /** @param list<string> $resourceKeys */
    private function validateRuntime(array $resourceKeys): void
    {
        if ($this->runtimeRegistry !== null && $this->runtimeValidator !== null) {
            $this->runtimeValidator->assertValid($this->runtimeRegistry->build(), $resourceKeys);
        }
    }

    /**
     * @param list<array{path:string,relative_path:string,owner_type:string,owner_code:string,source:string}> $files
     * @return list<array{resource:string,table_name:string,migration_owner:string,schema:\PTAdmin\Schema\Definition\Schema,metadata:array<string,mixed>,path:string,owner_type:string,owner_code:string,source:string}>
     */
    private function loadAll(array $files): array
    {
        $definitions = [];
        $owners = [];
        foreach ($files as $file) {
            try {
                $definition = $this->loader->resourceFromFile($file['path']);
            } catch (\Throwable $exception) {
                throw new RuntimeException('Unable to load Schema file '.$file['path'].': '.$exception->getMessage(), 0, $exception);
            }
            $resourceKey = $definition['resource'];
            $expected = preg_replace('/\.schema\.jsonc?$/i', '', basename($file['path'])) ?? '';
            if ($expected !== $resourceKey) {
                throw new RuntimeException(sprintf(
                    'Schema filename must match its resource key: %s declares %s.',
                    $file['path'],
                    $resourceKey
                ));
            }
            $issues = $definition['schema']->validate();
            if ($issues !== []) {
                throw new RuntimeException(sprintf(
                    'Schema file %s is invalid at %s: %s',
                    $file['path'],
                    (string) ($issues[0]['path'] ?? ''),
                    (string) ($issues[0]['message'] ?? 'validation failed')
                ));
            }
            if (isset($owners[$resourceKey])) {
                throw new RuntimeException(sprintf(
                    'Schema resource %s is declared more than once: %s and %s.',
                    $resourceKey,
                    $owners[$resourceKey],
                    $file['path']
                ));
            }
            $owners[$resourceKey] = $file['path'];
            $definitions[] = $definition + [
                'path' => $file['path'],
                'owner_type' => $file['owner_type'],
                'owner_code' => $file['owner_code'],
                'source' => $file['source'],
            ];
        }

        return $definitions;
    }

    /**
     * @param array{resource:string,path:string} $definition
     * @return array{resource:string,path:string,status:string,risk:string,checksum:string}
     */
    private function result(array $definition, string $status, string $risk, string $checksum): array
    {
        return [
            'resource' => $definition['resource'],
            'path' => $definition['path'],
            'status' => $status,
            'risk' => $risk,
            'checksum' => $checksum,
        ];
    }
}
