<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Tasks;

use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Tasks\TaskEventLog;
use PTAdmin\Core\Tasks\TaskException;
use PTAdmin\Core\Tasks\TaskManager;
use PTAdmin\Core\Tasks\TaskStore;

final class TaskManagerTest extends TestCase
{
    private string $directory;
    private TaskManager $tasks;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ptadmin-tasks-'.bin2hex(random_bytes(6));
        $store = new TaskStore($this->directory);
        $this->tasks = new TaskManager($store, new TaskEventLog($store));
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function testTaskLifecycleWritesStatePayloadResultAndEvents(): void
    {
        $task = $this->tasks->create(TaskManager::ADMIN_FRONTEND_UPDATE, ['version' => '1.2.0'], 'req-1');
        self::assertSame('pending', $task['status']);
        self::assertSame(['version' => '1.2.0'], $task['payload']);
        self::assertTrue($task['can_cancel']);

        $secretTask = $this->tasks->create(TaskManager::CORE_UPDATE, ['token' => 'private-value', 'message' => 'secret=private-value']);
        self::assertSame('[redacted]', $secretTask['payload']['token']);
        self::assertSame('secret=[redacted]', $secretTask['payload']['message']);

        $this->tasks->start($task['task_id'], 'download');
        $this->tasks->heartbeat($task['task_id'], 'verify', 45, 'token=private-value', ['token' => 'private-value']);
        self::assertSame('token=[redacted]', $this->tasks->get($task['task_id'])['message']);
        $completed = $this->tasks->succeed($task['task_id'], ['frontend_version' => '1.2.0']);

        self::assertSame('succeeded', $completed['status']);
        self::assertFalse($completed['can_cancel']);
        self::assertSame(100, $completed['progress']);
        self::assertSame('1.2.0', $completed['result']['data']['frontend_version']);
        $events = $this->tasks->events($task['task_id']);
        self::assertCount(4, $events['events']);
        self::assertSame(2, $events['events'][2]['cursor']);
        self::assertStringContainsString('token=[redacted]', $events['events'][2]['message']);
        self::assertSame('[redacted]', $events['events'][2]['data']['token']);
    }

    public function testInvalidTransitionsAndRetryLimitAreRejected(): void
    {
        $task = $this->tasks->create(TaskManager::CORE_UPDATE, [], null, 1);
        $this->expectException(TaskException::class);
        $this->tasks->succeed($task['task_id']);
    }

    public function testFailedTaskCanBeRetriedOnceWhenAttemptsRemain(): void
    {
        $task = $this->tasks->create(TaskManager::CORE_UPDATE, [], null, 2);
        $this->tasks->start($task['task_id']);
        $this->tasks->fail($task['task_id'], ['code' => 'network', 'message' => 'download failed']);
        self::assertTrue($this->tasks->get($task['task_id'])['can_retry']);
        $retried = $this->tasks->retry($task['task_id']);
        self::assertSame('pending', $retried['status']);
        $started = $this->tasks->start($task['task_id']);
        self::assertSame(2, $started['attempt']);
        $this->tasks->fail($task['task_id'], ['code' => 'network', 'message' => 'download failed']);
        self::assertFalse($this->tasks->get($task['task_id'])['can_retry']);
        $this->expectException(TaskException::class);
        $this->tasks->retry($task['task_id']);
    }

    public function testIdempotencyKeyReturnsTheOriginalTask(): void
    {
        $first = $this->tasks->create(TaskManager::ADDON_INSTALL, ['code' => 'demo'], null, 1, 'install-demo-1');
        $second = $this->tasks->create(TaskManager::ADDON_INSTALL, ['code' => 'demo'], null, 1, 'install-demo-1');

        self::assertSame($first['task_id'], $second['task_id']);
        self::assertCount(1, glob($this->directory.'/*/state.json') ?: []);
    }

    public function testIrreversibleRunningTaskCannotBeCancelled(): void
    {
        $task = $this->tasks->create(TaskManager::ADMIN_FRONTEND_UPDATE);
        $this->tasks->start($task['task_id'], 'publishing');

        try {
            $this->tasks->cancel($task['task_id']);
            self::fail('The task should not be cancellable while publishing.');
        } catch (TaskException $exception) {
            self::assertSame('task_not_cancellable', $exception->errorCode);
        }
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if (in_array($name, ['.', '..'], true)) {
                continue;
            }
            $entry = $path.'/'.$name;
            is_dir($entry) ? $this->remove($entry) : unlink($entry);
        }
        @rmdir($path);
    }
}
