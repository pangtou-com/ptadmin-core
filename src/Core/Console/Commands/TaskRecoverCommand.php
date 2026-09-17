<?php

declare(strict_types=1);

namespace PTAdmin\Core\Console\Commands;

use Illuminate\Console\Command;
use PTAdmin\Core\Tasks\TaskManager;

final class TaskRecoverCommand extends Command
{
    protected $signature = 'ptadmin:task:recover {--timeout= : Heartbeat timeout in seconds} {--no-requeue : Fail stale tasks instead of requeueing}';
    protected $description = 'Recover stale PTAdmin background tasks.';

    public function handle(TaskManager $tasks): int
    {
        $timeout = (int) ($this->option('timeout') ?: config('ptadmin.tasks.heartbeat_timeout_seconds', 900));
        $recovered = $tasks->recoverStale($timeout, !(bool) $this->option('no-requeue'));
        $this->info(sprintf('Recovered %d stale task(s).', count($recovered)));
        return self::SUCCESS;
    }
}
