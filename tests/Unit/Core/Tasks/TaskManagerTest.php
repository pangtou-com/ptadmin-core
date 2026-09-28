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

        $secretTask = $this->tasks->create(TaskManager::CORE_UPDATE, ['token' => 'private-value', 'message' => 'secret=private-value']);
        self::assertSame('[redacted]', $secretTask['payload']['token']);
        self::assertSame('secret=[redacted]', $secretTask['payload']['message']);
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

    public function testParentTaskSnapshotIncludesChildTasks(): void
    {
        $parent = $this->tasks->create(TaskManager::ADDON_INSTALL, ['code' => 'demo']);
        $child = $this->tasks->create(TaskManager::ADDON_DOWNLOAD, ['code' => 'demo'], null, 1, null, $parent['task_id']);

        $snapshot = $this->tasks->get($parent['task_id']);

        self::assertSame($parent['task_id'], $child['parent_task_id']);
        self::assertCount(1, $snapshot['children']);
        self::assertSame($child['task_id'], $snapshot['children'][0]['task_id']);
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

    public function testRetiredThemeActivationTaskCannotBeCreated(): void
    {
        try {
            $this->tasks->create(TaskManager::SITE_THEME_ACTIVATE, ['code' => 'classic', 'version' => '1.0.0']);
            self::fail('Theme activation must not create a task.');
        } catch (TaskException $exception) {
            self::assertSame('unsupported_task_type', $exception->errorCode);
        }
    }

    public function testModuleTaskRegistrationIsScopedToManagerAndCannotReplaceBuiltinTypes(): void
    {
        $this->tasks->registerType('cms.seo.push', 'cms.seo');
        $task = $this->tasks->create('cms.seo.push', ['platform' => 'indexnow']);
        self::assertSame('pending', $task['status']);
        self::assertSame('cms.seo', $this->tasks->resourceFor('cms.seo.push'));
        try {
            TaskManager::permissionFor('cms.seo.push');
            self::fail('Module payloads must not use the generic HTTP creation endpoint.');
        } catch (TaskException $exception) {
            self::assertSame('unsupported_task_type', $exception->errorCode);
        }
        $this->expectException(TaskException::class);
        $this->tasks->registerType(TaskManager::CORE_UPDATE, 'cms.seo');
    }

    public function testAnotherManagerCannotCreateAnOperationUntilTheCurrentOneEnds(): void
    {
        $first = $this->tasks->create(TaskManager::ADDON_INSTALL);
        $store = new TaskStore($this->directory);
        $otherManager = new TaskManager($store, new TaskEventLog($store));
        foreach (['pending', 'running'] as $state) {
            if ($state === 'running') {
                $this->tasks->start($first['task_id']);
            }
            try {
                $otherManager->create(TaskManager::ADMIN_FRONTEND_UPDATE);
                self::fail('Another operation must be rejected.');
            } catch (TaskException $exception) {
                self::assertSame('task_in_progress', $exception->errorCode);
                self::assertSame($first['task_id'], $exception->details['task_id']);
            }
        }
        $this->tasks->succeed($first['task_id']);
        self::assertSame('pending', $otherManager->create(TaskManager::ADMIN_FRONTEND_UPDATE)['status']);
    }

    public function testChildrenRunSequentiallyWithinOneOperation(): void
    {
        $parent = $this->tasks->create(TaskManager::ADDON_INSTALL);
        $first = $this->tasks->create(TaskManager::ADDON_DOWNLOAD, [], null, 1, null, $parent['task_id']);
        $second = $this->tasks->create(TaskManager::ADDON_INSTALL, [], null, 1, null, $parent['task_id']);
        $this->tasks->start($parent['task_id']);
        $this->tasks->start($first['task_id']);
        try {
            $this->tasks->start($second['task_id']);
            self::fail('Sibling steps must not run concurrently.');
        } catch (TaskException $exception) {
            self::assertSame('task_in_progress', $exception->errorCode);
            self::assertSame($parent['task_id'], $exception->details['task_id']);
        }
        self::assertSame('pending', $this->tasks->get($second['task_id'])['status']);
        $this->tasks->succeed($first['task_id']);
        self::assertSame('running', $this->tasks->start($second['task_id'])['status']);
    }

    public function testRetryCannotOverlapAnotherOperationAndFailureKeepsItsStage(): void
    {
        $first = $this->tasks->create(TaskManager::ADDON_INSTALL, [], null, 2);
        $this->tasks->start($first['task_id']);
        $this->tasks->heartbeat($first['task_id'], 'verify', 45, 'Checking package');
        $failed = $this->tasks->fail($first['task_id'], ['message' => 'Checksum mismatch']);
        self::assertSame('verify', $failed['stage']);
        self::assertSame(45, $failed['progress']);
        $second = $this->tasks->create(TaskManager::ADMIN_FRONTEND_UPDATE);
        try {
            $this->tasks->retry($first['task_id']);
            self::fail('Retry must respect the operation lock.');
        } catch (TaskException $exception) {
            self::assertSame('task_in_progress', $exception->errorCode);
        }
        self::assertSame('failed', $this->tasks->get($first['task_id'])['status']);
        $this->tasks->cancel($second['task_id']);
        self::assertSame('pending', $this->tasks->retry($first['task_id'])['status']);
    }

    public function testUnrelatedModuleTasksDoNotBlockInstallation(): void
    {
        $this->tasks->registerType('cms.seo.push', 'cms.seo');
        $this->tasks->create('cms.seo.push');
        self::assertSame('pending', $this->tasks->create(TaskManager::ADDON_INSTALL)['status']);
    }

    public function testCancellingAGroupCancelsQueuedChildrenButCannotInterruptRunningChildren(): void
    {
        $parent = $this->tasks->create(TaskManager::ADDON_INSTALL);
        $child = $this->tasks->create(TaskManager::ADDON_DOWNLOAD, [], null, 1, null, $parent['task_id']);
        $this->tasks->start($parent['task_id']);
        $this->tasks->start($child['task_id']);
        self::assertFalse($this->tasks->get($parent['task_id'])['can_cancel']);
        try {
            $this->tasks->cancel($parent['task_id']);
            self::fail('The parent must not abandon a running child.');
        } catch (TaskException $exception) {
            self::assertSame('task_not_cancellable', $exception->errorCode);
        }
        $this->tasks->succeed($child['task_id']);
        $queued = $this->tasks->create(TaskManager::ADDON_INSTALL, [], null, 1, null, $parent['task_id']);
        $this->tasks->cancel($parent['task_id']);
        self::assertSame('cancelled', $this->tasks->get($queued['task_id'])['status']);
        self::assertSame('pending', $this->tasks->create(TaskManager::ADMIN_FRONTEND_UPDATE)['status']);
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
