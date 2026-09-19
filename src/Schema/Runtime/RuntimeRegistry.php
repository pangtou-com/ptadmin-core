<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use ReflectionClass;
use ReflectionMethod;

final class RuntimeRegistry
{
    public const FORMAT = 1;

    private const OWNER_TYPES = ['core', 'app', 'addon'];

    private const HOOK_STAGES = [
        'create.prepare', 'create.before', 'create.after',
        'update.prepare', 'update.before', 'update.after',
        'delete.before', 'delete.after',
        'restore.before', 'restore.after',
        'purge.before', 'purge.after',
        'output.transform',
    ];

    private const CRUD_ACTIONS = ['list', 'view', 'create', 'update', 'delete', 'restore', 'purge'];

    /** @var list<array<string, mixed>> */
    private array $hooks = [];
    /** @var list<array<string, mixed>> */
    private array $actions = [];
    /** @var list<array<string, mixed>> */
    private array $events = [];
    private int $sequence = 0;
    private bool $built = false;

    public function forOwner(string $ownerType, string $ownerCode): RuntimeContributor
    {
        $this->assertMutable();
        $owner = $this->normalizeOwner($ownerType, $ownerCode);

        return new RuntimeContributor($this, $owner);
    }

    public function build(): RuntimeRegistrySnapshot
    {
        $this->assertMutable();
        $this->built = true;

        foreach ($this->hooks as $binding) {
            $this->validateBinding($binding, 'hook');
        }
        foreach ($this->actions as $binding) {
            $this->validateBinding($binding, 'action');
            if (in_array($binding['action_key'], self::CRUD_ACTIONS, true)) {
                throw $this->diagnostic('action_reserved', 'Custom Action cannot override a CRUD Action.', $binding);
            }
        }
        foreach ($this->events as $binding) {
            $this->validateBinding($binding, 'event');
        }

        $hooks = $this->sortedBindings($this->hooks);
        $events = $this->sortedBindings($this->events);

        $actions = [];
        foreach ($this->actions as $binding) {
            $key = $binding['resource_key'].'|'.$binding['action_key'];
            if (isset($actions[$key])) {
                throw $this->diagnostic('action_binding_duplicate', 'Runtime Action binding is duplicated.', $binding);
            }
            $actions[$key] = true;
        }

        $descriptor = [
            'format' => self::FORMAT,
            'resources' => [],
        ];
        foreach ($hooks as $binding) {
            $descriptor['resources'][$binding['resource_key']]['hooks'][$binding['stage']][] = $binding['descriptor'];
        }
        foreach ($this->actions as $binding) {
            $descriptor['resources'][$binding['resource_key']]['actions'][$binding['action_key']] = $binding['descriptor'];
        }
        foreach ($events as $binding) {
            $descriptor['resources'][$binding['resource_key']]['events'][$binding['event_key']][] = $binding['descriptor'];
        }

        $snapshot = new RuntimeRegistrySnapshot($descriptor);
        $descriptor['checksum'] = $snapshot->checksum();

        return new RuntimeRegistrySnapshot($descriptor);
    }

    /** @param list<array<string, mixed>> $bindings @return list<array<string, mixed>> */
    private function sortedBindings(array $bindings): array
    {
        usort($bindings, static function (array $left, array $right): int {
            $priority = ((int) ($right['priority'] ?? 0)) <=> ((int) ($left['priority'] ?? 0));

            return $priority !== 0
                ? $priority
                : ((int) $left['sequence']) <=> ((int) $right['sequence']);
        });

        return $bindings;
    }

    /** @param array<string, scalar> $owner */
    public function addHook(array $owner, string $resourceKey, string $stage, string $service, string $method, int $priority): void
    {
        $this->assertMutable();
        $this->hooks[] = $this->binding($owner, $resourceKey, $service, $method, [
            'stage' => $stage,
            'priority' => $priority,
        ]);
    }

    /** @param array<string, scalar> $owner */
    public function addAction(array $owner, string $resourceKey, string $actionKey, string $service, string $method): void
    {
        $this->assertMutable();
        $this->actions[] = $this->binding($owner, $resourceKey, $service, $method, [
            'action_key' => $actionKey,
        ]);
    }

    /** @param array<string, scalar> $owner */
    public function addEvent(array $owner, string $resourceKey, string $eventKey, string $service, string $method, EventMode $mode, int $priority): void
    {
        $this->assertMutable();
        $this->events[] = $this->binding($owner, $resourceKey, $service, $method, [
            'event_key' => $eventKey,
            'mode' => $mode->value,
            'priority' => $priority,
        ]);
    }

    /** @param array<string, scalar> $owner @param array<string, scalar> $extra @return array<string, mixed> */
    private function binding(array $owner, string $resourceKey, string $service, string $method, array $extra): array
    {
        return [
            'resource_key' => $resourceKey,
            'service' => $service,
            'method' => $method,
            'owner' => $owner,
            'sequence' => ++$this->sequence,
            ...$extra,
            'descriptor' => $extra + [
                'owner' => $owner,
                'service' => $service,
                'method' => $method,
                'sequence' => $this->sequence,
            ],
        ];
    }

    /** @param array<string, mixed> $binding */
    private function validateBinding(array $binding, string $kind): void
    {
        $resourceKey = (string) $binding['resource_key'];
        if (!preg_match('/^[a-z][a-z0-9_-]{0,99}(?:\.[a-z][a-z0-9_-]{0,99})+$/', $resourceKey)) {
            throw $this->diagnostic('resource_key_invalid', 'Runtime resource key is invalid.', $binding);
        }
        if ($kind === 'hook' && !in_array($binding['stage'], self::HOOK_STAGES, true)) {
            throw $this->diagnostic('hook_stage_invalid', 'Runtime Hook stage is invalid.', $binding);
        }
        if ($kind === 'action' && !preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', (string) $binding['action_key'])) {
            throw $this->diagnostic('action_key_invalid', 'Runtime Action key is invalid.', $binding);
        }
        if ($kind === 'event' && !preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', (string) $binding['event_key'])) {
            throw $this->diagnostic('event_key_invalid', 'Runtime Event key is invalid.', $binding);
        }
        $service = (string) $binding['service'];
        $method = (string) $binding['method'];
        if ($service === '' || $method === '' || !class_exists($service)) {
            throw $this->diagnostic('runtime_service_invalid', 'Runtime service class is unavailable.', $binding);
        }
        $reflection = new ReflectionClass($service);
        if (!$reflection->isInstantiable()) {
            throw $this->diagnostic('runtime_service_invalid', 'Runtime service class is not instantiable.', $binding);
        }
        if (!$reflection->hasMethod($method) || !$reflection->getMethod($method)->isPublic()) {
            throw $this->diagnostic('runtime_method_invalid', 'Runtime service method is not public.', $binding);
        }
    }

    /** @param array<string, mixed> $binding */
    private function diagnostic(string $code, string $message, array $binding): RuntimeDiagnostic
    {
        return new RuntimeDiagnostic($code, $message, [
            'resource_key' => $binding['resource_key'] ?? null,
            'owner' => isset($binding['owner']) ? json_encode($binding['owner'], JSON_UNESCAPED_SLASHES) : null,
            'service' => $binding['service'] ?? null,
            'method' => $binding['method'] ?? null,
            'stage' => $binding['stage'] ?? null,
            'action_key' => $binding['action_key'] ?? null,
            'event_key' => $binding['event_key'] ?? null,
        ]);
    }

    /** @return array{type:string,code:string} */
    private function normalizeOwner(string $type, string $code): array
    {
        if (!in_array($type, self::OWNER_TYPES, true) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $code)) {
            throw new RuntimeDiagnostic('owner_invalid', 'Runtime Owner is invalid.', ['owner_type' => $type, 'owner_code' => $code]);
        }
        if ($type === 'core' && $code !== 'ptadmin') {
            throw new RuntimeDiagnostic('owner_invalid', 'Core Runtime Owner must be core/ptadmin.', ['owner_type' => $type, 'owner_code' => $code]);
        }
        if ($type === 'app' && $code !== 'host') {
            throw new RuntimeDiagnostic('owner_invalid', 'Application Runtime Owner must be app/host.', ['owner_type' => $type, 'owner_code' => $code]);
        }

        return ['type' => $type, 'code' => $code];
    }

    private function assertMutable(): void
    {
        if ($this->built) {
            throw new RuntimeDiagnostic('runtime_registry_frozen', 'RuntimeRegistry has already been built.');
        }
    }
}
