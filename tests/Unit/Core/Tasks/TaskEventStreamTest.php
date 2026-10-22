<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Tasks;

use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Tasks\TaskEventLog;
use PTAdmin\Core\Tasks\TaskEventStream;
use PTAdmin\Core\Tasks\TaskManager;
use PTAdmin\Core\Tasks\TaskStore;

final class TaskEventStreamTest extends TestCase
{
    private string $directory;
    private TaskManager $tasks;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ptadmin-sse-'.bin2hex(random_bytes(6));
        $store = new TaskStore($this->directory);
        $this->tasks = new TaskManager($store, new TaskEventLog($store));
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testStreamsEveryPageAndTerminalFailureWithResumeCursor(): void
    {
        $task = $this->tasks->create(TaskManager::CORE_UPDATE, []);
        $id = $task['task_id'];
        $this->tasks->start($id);
        for ($index = 0; $index < 505; $index++) {
            $this->tasks->heartbeat($id, 'download', 10, '下载中');
        }
        $this->tasks->fail($id, ['code' => 'download_failed', 'message' => '下载失败']);
        $frames = iterator_to_array((new TaskEventStream($this->tasks))->frames($id, 2, 0, 0));
        $output = implode('', $frames);
        self::assertSame(506, substr_count($output, 'event: log'));
        self::assertStringContainsString("id: 508\nevent: complete", $output);
        self::assertStringNotContainsString('Task queued.', $output);
        self::assertStringContainsString('下载失败', $output);
        self::assertStringContainsString('"error_code":"download_failed"', $output);
        self::assertLessThan(strpos($output, 'event: complete'), strpos($output, 'event: snapshot'));
    }

    public function testRunningStreamRotatesWithoutFinishingOrExecutingTask(): void
    {
        $task = $this->tasks->create(TaskManager::CORE_UPDATE, []);
        $output = implode('', iterator_to_array((new TaskEventStream($this->tasks))->frames($task['task_id'], 0, 0, 0)));
        self::assertStringContainsString('event: reconnect', $output);
        self::assertStringNotContainsString('event: complete', $output);
        self::assertSame('pending', $this->tasks->get($task['task_id'])['status']);
    }

    public function testRetryMarksFreshLogBoundaryWithoutDeletingHistory(): void
    {
        $task = $this->tasks->create(TaskManager::CORE_UPDATE, [], null, 2);
        $id = $task['task_id'];
        $this->tasks->start($id);
        $this->tasks->fail($id, ['message' => '第一次失败']);
        $retried = $this->tasks->retry($id);
        self::assertSame(3, $retried['attempt_start_cursor']);
        self::assertNull($retried['started_at']);
        self::assertNull($retried['finished_at']);
        self::assertCount(4, $this->tasks->events($id)['events']);
        self::assertSame('Task requeued.', $this->tasks->events($id, 3)['events'][0]['message']);
    }
}
