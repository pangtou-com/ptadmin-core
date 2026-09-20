<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Console\Commands;

use Illuminate\Console\Command;
use PTAdmin\Schema\Runtime\RuntimeCacheStore;
use PTAdmin\Schema\Runtime\RuntimeRegistry;
use PTAdmin\Schema\Runtime\RuntimeRegistryValidator;
use Throwable;

final class RuntimeValidateCommand extends Command
{
    protected $signature = 'runtime:validate';
    protected $description = '校验 Runtime 绑定与 active Schema 的 Action 声明';

    public function handle(RuntimeRegistry $registry, RuntimeCacheStore $cache, RuntimeRegistryValidator $validator): int
    {
        try {
            $snapshot = $cache->load(static fn () => $registry->build());
            $diagnostics = $validator->validate($snapshot);
            if ($diagnostics !== []) {
                $this->table(['code', 'resource_key', 'action_key'], array_map(static fn (array $item): array => [
                    $item['code'] ?? '', $item['resource_key'] ?? '', $item['action_key'] ?? '',
                ], $diagnostics));

                return self::FAILURE;
            }
            $this->components->info('Runtime 绑定与 active Schema 校验通过。');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
