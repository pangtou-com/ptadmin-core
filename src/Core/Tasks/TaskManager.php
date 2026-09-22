<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tasks;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class TaskManager
{
    public const CORE_UPDATE = 'core.update';
    public const ADMIN_FRONTEND_UPDATE = 'admin_frontend.update';
    public const ADDON_DOWNLOAD = 'addon.download';
    public const ADDON_INSTALL = 'addon.install';
    public const ADDON_UPDATE = 'addon.update';
    public const ADDON_UNINSTALL = 'addon.uninstall';
    public const ADDON_FRONTEND_SYNC = 'addon.frontend_sync';
    public const ADDON_ENABLE = 'addon.enable';
    public const ADDON_DISABLE = 'addon.disable';
    public const SITE_THEME_INSTALL = 'site_theme.install';
    public const SITE_THEME_ACTIVATE = 'site_theme.activate';
    public const SITE_THEME_REMOVE = 'site_theme.remove';

    /** @var array<string, string> */
    private const TASK_PERMISSIONS = [
        self::CORE_UPDATE => 'core.update',
        self::ADMIN_FRONTEND_UPDATE => 'admin.frontend',
        self::ADDON_DOWNLOAD => 'addon.download',
        self::ADDON_INSTALL => 'addon.install',
        self::ADDON_UPDATE => 'addon.update',
        self::ADDON_UNINSTALL => 'addon.uninstall',
        self::ADDON_FRONTEND_SYNC => 'addon.frontend',
        self::ADDON_ENABLE => 'addon.enable',
        self::ADDON_DISABLE => 'addon.disable',
        self::SITE_THEME_INSTALL => 'site.theme.install',
        self::SITE_THEME_ACTIVATE => 'site.theme.activate',
        self::SITE_THEME_REMOVE => 'site.theme.remove',
    ];

    /** @var list<string> */
    private const RETIRED_TASK_TYPES = [
        self::SITE_THEME_ACTIVATE,
    ];

    /** @var array<string, array<int, string>> */
    private const TRANSITIONS = [
        'pending' => ['running', 'cancelled'],
        'running' => ['pending', 'succeeded', 'failed', 'blocked', 'cancelled'],
        'failed' => ['pending'],
        'blocked' => ['pending'],
        'succeeded' => [],
        'cancelled' => [],
    ];

    /** Stages that may already have changed user assets or dependencies. */
    private const IRREVERSIBLE_STAGES = [
        'composer',
        'migrate',
        'installing',
        'publishing',
        'importing_data',
        'publish',
        'replacing',
        'replace',
        'activating',
        'activate',
    ];

    private TaskStore $store;
    private TaskEventLog $events;

    public function __construct(TaskStore $store, TaskEventLog $events)
    {
        $this->store = $store;
        $this->events = $events;
    }

    /** @param array<string, mixed> $payload */
    public function create(string $type, array $payload = [], ?string $requestId = null, int $maxAttempts = 1, ?string $idempotencyKey = null): array
    {
        if (!isset(self::TASK_PERMISSIONS[$type]) || in_array($type, self::RETIRED_TASK_TYPES, true)) {
            throw new TaskException('The task type is unsupported.', 'unsupported_task_type', ['type' => $type]);
        }
        return $this->store->withCreationLock(function () use ($type, $payload, $requestId, $maxAttempts, $idempotencyKey): array {
            if (null !== $idempotencyKey) {
                if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $idempotencyKey) !== 1) {
                    throw new TaskException('The task idempotency key is invalid.', 'invalid_idempotency_key');
                }
                $existing = $this->store->findByIdempotencyKey($idempotencyKey);
                if (is_array($existing)) {
                    if (($existing['type'] ?? null) !== $type) {
                        throw new TaskException('The idempotency key is already used by another task type.', 'idempotency_key_conflict');
                    }
                    return $this->snapshot((string) $existing['task_id'], $existing);
                }
            }
            $taskId = $this->uuid();
            $createdAt = $this->timestamp();
            $state = [
                'task_id' => $taskId,
                'type' => $type,
                'status' => 'pending',
                'stage' => 'queued',
                'progress' => 0,
                'attempt' => 0,
                'max_attempts' => max(1, min(20, $maxAttempts)),
                'message' => 'Task queued.',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'started_at' => null,
                'finished_at' => null,
                'heartbeat_at' => null,
                'error_code' => null,
                'error_message' => null,
                'request_id' => $requestId,
                'idempotency_key' => $idempotencyKey,
            ];
            $this->store->initialize($taskId);
            $this->store->writeState($taskId, $state);
            $this->store->writePayload($taskId, $payload);
            $this->events->append($taskId, $type, 'queued', 'pending', 0, 'Task queued.', [], $requestId);
            return $this->snapshot($taskId);
        });
    }

    /** @return array<string, mixed> */
    public function get(string $taskId): array
    {
        $state = $this->store->readState($taskId);
        if (null === $state) {
            throw new TaskException('The task was not found.', 'task_not_found', ['task_id' => $taskId]);
        }
        return $this->snapshot($taskId, $state);
    }

    /** Internal task view containing the original payload required by an executor. */
    public function getForExecution(string $taskId): array
    {
        $state = $this->assertExists($taskId);
        $snapshot = $this->snapshot($taskId, $state);
        $snapshot['payload'] = $this->store->readPayload($taskId) ?? [];
        return $snapshot;
    }

    /** @return array{events: array<int, array<string, mixed>>, next_cursor: int, has_more: bool} */
    public function events(string $taskId, int $cursor = 0, int $limit = 100): array
    {
        $this->assertExists($taskId);
        return $this->events->read($taskId, $cursor, $limit);
    }

    /** @return array{total:int, results:array<int, array<string, mixed>>} */
    public function list(int $limit = 50): array
    {
        $snapshots = [];
        foreach (glob($this->store->root().'/*/state.json') ?: [] as $path) {
            $taskId = basename(dirname($path));
            $state = $this->store->readState($taskId);
            if (is_array($state)) {
                $snapshots[] = $this->snapshot($taskId, $state);
            }
        }
        usort($snapshots, static fn (array $left, array $right): int => strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? '')));
        $total = count($snapshots);
        return ['total' => $total, 'results' => array_slice($snapshots, 0, max(1, min(200, $limit)))];
    }

    /** @return array<string, mixed> */
    public function start(string $taskId, string $stage = 'running', ?string $requestId = null): array
    {
        return $this->transition($taskId, 'running', $stage, 0, 'Task started.', [], $requestId, static function (array $state): array {
            $state['attempt'] = (int) ($state['attempt'] ?? 0) + 1;
            return $state;
        });
    }

    /** @return array<string, mixed> */
    public function heartbeat(string $taskId, string $stage, int|float $progress, string $message = '', array $data = [], ?string $requestId = null): array
    {
        $state = $this->assertExists($taskId);
        if (($state['status'] ?? null) !== 'running') {
            throw new TaskException('Only running tasks can send a heartbeat.', 'invalid_task_status');
        }
        $updated = $this->store->updateState($taskId, function (array $current) use ($stage, $progress, $message): array {
            $current['stage'] = $stage;
            $current['progress'] = max(0, min(100, $progress));
            if ($message !== '') {
                $current['message'] = TaskRedactor::message($message);
            }
            $current['heartbeat_at'] = $this->timestamp();
            $current['updated_at'] = $this->timestamp();
            return $current;
        });
        $this->events->append($taskId, (string) $updated['type'], $stage, 'running', $updated['progress'], $message, $data, $requestId ?? ($updated['request_id'] ?? null));
        return $this->snapshot($taskId, $updated);
    }

    /** @param array<string, mixed> $result */
    public function succeed(string $taskId, array $result = [], string $message = 'Task completed.', ?string $requestId = null): array
    {
        $snapshot = $this->transition($taskId, 'succeeded', 'completed', 100, $message, $result, $requestId);
        $this->store->writeResult($taskId, ['status' => 'succeeded', 'data' => TaskRedactor::array($result), 'completed_at' => $this->timestamp()]);
        return $this->get($taskId);
    }

    /** @param array<string, mixed> $error */
    public function fail(string $taskId, array $error, string $stage = 'failed', ?string $requestId = null): array
    {
        $this->transition($taskId, 'failed', $stage, 0, (string) ($error['message'] ?? 'Task failed.'), $error, $requestId);
        $this->store->updateState($taskId, function (array $state) use ($error): array {
            $state['error_code'] = (string) ($error['code'] ?? 'task_failed');
            $state['error_message'] = TaskRedactor::message((string) ($error['message'] ?? 'Task failed.'));
            return $state;
        });
        $this->store->writeResult($taskId, ['status' => 'failed', 'error' => TaskRedactor::array($error), 'failed_at' => $this->timestamp()]);
        return $this->get($taskId);
    }

    /** @param array<string, mixed> $reason */
    public function block(string $taskId, array $reason, string $stage = 'blocked', ?string $requestId = null): array
    {
        $this->transition($taskId, 'blocked', $stage, 0, (string) ($reason['message'] ?? 'Task blocked.'), $reason, $requestId);
        $this->store->updateState($taskId, function (array $state) use ($reason): array {
            $state['error_code'] = (string) ($reason['code'] ?? 'task_blocked');
            $state['error_message'] = TaskRedactor::message((string) ($reason['message'] ?? 'Task blocked.'));
            return $state;
        });
        $this->store->writeResult($taskId, ['status' => 'blocked', 'reason' => TaskRedactor::array($reason), 'blocked_at' => $this->timestamp()]);
        return $this->get($taskId);
    }

    /** @return array<string, mixed> */
    public function cancel(string $taskId, string $message = 'Task cancelled.', ?string $requestId = null): array
    {
        $state = $this->assertExists($taskId);
        if (($state['status'] ?? null) === 'running' && in_array((string) ($state['stage'] ?? ''), self::IRREVERSIBLE_STAGES, true)) {
            throw new TaskException('The task cannot be cancelled after its irreversible stage has started.', 'task_not_cancellable', ['stage' => $state['stage']]);
        }
        return $this->transition($taskId, 'cancelled', 'cancelled', 0, $message, [], $requestId);
    }

    /** @return array<string, mixed> */
    public function retry(string $taskId, ?string $requestId = null): array
    {
        $state = $this->assertExists($taskId);
        if (!in_array($state['status'] ?? null, ['failed', 'blocked'], true)) {
            throw new TaskException('Only failed or blocked tasks can be retried.', 'invalid_task_status');
        }
        if ((int) ($state['attempt'] ?? 0) >= (int) ($state['max_attempts'] ?? 1)) {
            throw new TaskException('The task has reached its retry limit.', 'retry_limit_reached');
        }
        return $this->transition($taskId, 'pending', 'queued', 0, 'Task requeued.', [], $requestId, static function (array $current): array {
            $current['error_code'] = null;
            $current['error_message'] = null;
            return $current;
        });
    }

    /**
     * Mark tasks with an old heartbeat as failed, or requeue them when attempts remain.
     * @return array<int, array<string, mixed>>
     */
    public function recoverStale(int $timeoutSeconds = 900, bool $requeue = true, ?string $requestId = null): array
    {
        $result = [];
        $cutoff = time() - max(1, $timeoutSeconds);
        foreach (glob($this->store->root().'/*/state.json') ?: [] as $path) {
            $taskId = basename(dirname($path));
            $state = $this->store->readState($taskId);
            if (!is_array($state) || ($state['status'] ?? null) !== 'running') {
                continue;
            }
            $heartbeat = strtotime((string) ($state['heartbeat_at'] ?? $state['updated_at'] ?? ''));
            if (false === $heartbeat || $heartbeat > $cutoff) {
                continue;
            }
            try {
                if ($requeue && (int) ($state['attempt'] ?? 0) < (int) ($state['max_attempts'] ?? 1)) {
                    $result[] = $this->transition($taskId, 'pending', 'queued', 0, 'Stale task requeued.', [], $requestId);
                } else {
                    $result[] = $this->fail($taskId, ['code' => 'heartbeat_timeout', 'message' => 'Task heartbeat timed out.'], 'recovery', $requestId);
                }
            } catch (TaskException) {
                continue;
            }
        }
        return $result;
    }

    public static function permissionFor(string $type): string
    {
        if (!isset(self::TASK_PERMISSIONS[$type])) {
            throw new TaskException('The task type is unsupported.', 'unsupported_task_type', ['type' => $type]);
        }
        return self::TASK_PERMISSIONS[$type];
    }

    /** @param array<string, mixed> $data */
    private function transition(string $taskId, string $status, string $stage, int|float $progress, string $message, array $data, ?string $requestId, ?Closure $mutator = null): array
    {
        $state = $this->assertExists($taskId);
        if (!in_array($status, self::TRANSITIONS[(string) ($state['status'] ?? '')] ?? [], true)) {
            throw new TaskException('The task status transition is invalid.', 'invalid_task_transition', ['from' => $state['status'] ?? null, 'to' => $status]);
        }
        $updated = $this->store->updateState($taskId, function (array $current) use ($status, $stage, $progress, $message, $mutator, $requestId): array {
            if (!in_array($status, self::TRANSITIONS[(string) ($current['status'] ?? '')] ?? [], true)) {
                throw new TaskException('The task status transition is invalid.', 'invalid_task_transition');
            }
            $current['status'] = $status;
            $current['stage'] = $stage;
            $current['progress'] = max(0, min(100, $progress));
            $current['message'] = TaskRedactor::message($message);
            $current['updated_at'] = $this->timestamp();
            $current['heartbeat_at'] = 'running' === $status ? $this->timestamp() : null;
            if ('running' === $status && null === ($current['started_at'] ?? null)) {
                $current['started_at'] = $this->timestamp();
            }
            if (in_array($status, ['succeeded', 'failed', 'blocked', 'cancelled'], true)) {
                $current['finished_at'] = $this->timestamp();
            }
            if (null !== $requestId) {
                $current['request_id'] = $requestId;
            }
            return null !== $mutator ? $mutator($current) : $current;
        });
        $this->events->append($taskId, (string) $updated['type'], $stage, $status, $updated['progress'], $message, $data, $requestId ?? ($updated['request_id'] ?? null));
        return $this->snapshot($taskId, $updated);
    }

    /** @return array<string, mixed> */
    private function snapshot(string $taskId, ?array $state = null): array
    {
        $state ??= $this->store->readState($taskId) ?? [];
        $status = (string) ($state['status'] ?? 'pending');
        $attempt = (int) ($state['attempt'] ?? 0);
        $maxAttempts = (int) ($state['max_attempts'] ?? 1);
        $stage = (string) ($state['stage'] ?? '');
        $canCancel = in_array($status, ['pending', 'running'], true)
            && !in_array($stage, self::IRREVERSIBLE_STAGES, true);
        $canRetry = in_array($status, ['failed', 'blocked'], true) && $attempt < $maxAttempts;

        return $state + [
            'payload' => TaskRedactor::array($this->store->readPayload($taskId) ?? []),
            'result' => $this->store->readResult($taskId),
            'can_retry' => $canRetry,
            'can_cancel' => $canCancel,
        ];
    }

    /** @return array<string, mixed> */
    private function assertExists(string $taskId): array
    {
        $state = $this->store->readState($taskId);
        if (null === $state) {
            throw new TaskException('The task was not found.', 'task_not_found', ['task_id' => $taskId]);
        }
        return $state;
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
