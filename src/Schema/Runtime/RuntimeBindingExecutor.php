<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

use Illuminate\Contracts\Container\Container;
use PTAdmin\Schema\Contract\SchemaError;
use Throwable;

/**
 * 从不可变 Registry 描述符执行当前资源所需的绑定，并按需解析服务。
 */
final class RuntimeBindingExecutor
{
    public function __construct(private Container $container)
    {
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function prepare(RuntimeRegistrySnapshot $snapshot, RuntimeHookContext $context, array $data): array
    {
        return $this->pipeline($snapshot, $context, $data);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function transform(RuntimeRegistrySnapshot $snapshot, RuntimeHookContext $context, array $data): array
    {
        return $this->pipeline($snapshot, $context, $data);
    }

    public function run(RuntimeRegistrySnapshot $snapshot, RuntimeHookContext $context): void
    {
        foreach ($this->bindings($snapshot, $context) as $binding) {
            $this->invoke($binding, $context);
        }
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function action(RuntimeRegistrySnapshot $snapshot, RuntimeHookContext $context, array $input): array
    {
        $resource = $snapshot->toArray()['resources'][$context->resourceKey] ?? null;
        $binding = is_array($resource) ? ($resource['actions'][$context->actionKey ?? ''] ?? null) : null;
        if (!is_array($binding)) {
            throw new RuntimeDiagnostic('action_binding_missing', 'Runtime Action binding is unavailable.', [
                'resource_key' => $context->resourceKey,
                'action_key' => $context->actionKey,
            ]);
        }
        $result = $this->invoke($binding, $context, $input);
        if (!is_array($result)) {
            throw $this->returnDiagnostic($binding, $context, 'Action handler must return an array.');
        }

        return $result;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function pipeline(RuntimeRegistrySnapshot $snapshot, RuntimeHookContext $context, array $data): array
    {
        foreach ($this->bindings($snapshot, $context) as $binding) {
            $result = $this->invoke($binding, $context, $data);
            if (!is_array($result)) {
                throw $this->returnDiagnostic($binding, $context, 'Runtime Hook handler must return an array.');
            }
            $data = $result;
        }

        return $data;
    }

    /** @return list<array<string, mixed>> */
    private function bindings(RuntimeRegistrySnapshot $snapshot, RuntimeHookContext $context): array
    {
        $resource = $snapshot->toArray()['resources'][$context->resourceKey] ?? null;
        if (!is_array($resource)) {
            return [];
        }
        $bindings = $resource['hooks'][$context->stage] ?? [];

        return is_array($bindings) ? array_values(array_filter($bindings, 'is_array')) : [];
    }

    private function invoke(array $binding, RuntimeHookContext $context, mixed ...$arguments): mixed
    {
        $service = (string) ($binding['service'] ?? '');
        $method = (string) ($binding['method'] ?? '');
        try {
            $instance = $this->container->make($service);
            return $instance->{$method}($context, ...$arguments);
        } catch (RuntimeDiagnostic $exception) {
            throw $exception;
        } catch (SchemaError $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeDiagnostic(
                'runtime_handler_failed',
                'Runtime handler failed: '.$exception->getMessage(),
                $this->diagnosticContext($binding, $context),
            );
        }
    }

    /** @param array<string, mixed> $binding @return array<string, scalar|null> */
    private function diagnosticContext(array $binding, RuntimeHookContext $context): array
    {
        return [
            'resource_key' => $context->resourceKey,
            'stage' => $context->stage,
            'owner' => isset($binding['owner']) ? json_encode($binding['owner'], JSON_UNESCAPED_SLASHES) : null,
            'service' => $binding['service'] ?? null,
            'method' => $binding['method'] ?? null,
            'action_key' => $context->actionKey,
        ];
    }

    /** @param array<string, mixed> $binding */
    private function returnDiagnostic(array $binding, RuntimeHookContext $context, string $message): RuntimeDiagnostic
    {
        return new RuntimeDiagnostic('runtime_handler_return_invalid', $message, $this->diagnosticContext($binding, $context));
    }
}
