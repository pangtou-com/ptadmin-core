<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Tasks;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Tasks\TaskEventLog;
use PTAdmin\Core\Tasks\TaskException;
use PTAdmin\Core\Tasks\TaskHistoryCleaner;
use PTAdmin\Core\Tasks\TaskManager;
use PTAdmin\Core\Tasks\TaskStore;

final class TaskHistoryTest extends TestCase
{
    private string $directory;
    private TaskStore $store;
    private TaskManager $tasks;
    private TaskHistoryCleaner $cleaner;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ptadmin-history-'.bin2hex(random_bytes(6));
        $this->store = new TaskStore($this->directory);
        $this->tasks = new TaskManager($this->store, new TaskEventLog($this->store));
        $this->cleaner = new TaskHistoryCleaner($this->store);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
    }

    public function testHistoryPaginatesRootsBeyondTwoHundredAndKeepsActiveOutsideFilters(): void
    {
        for ($number = 1; $number <= 225; $number++) {
            $this->record($number);
        }
        for ($number = 226; $number <= 240; $number++) {
            $this->record($number, 'succeeded', $this->id(225));
        }
        $this->record(241, 'running');
        $this->record(242, 'failed');
        $this->record(243, 'succeeded', null, 'cms.seo.push');
        $first = $this->tasks->history(20);
        self::assertSame(226, $first['total']);
        self::assertSame([$this->id(241)], array_column($first['active'], 'task_id'));
        self::assertCount(20, $first['results']);
        self::assertArrayNotHasKey('children', $first['results'][0]);
        self::assertArrayNotHasKey('result', $first['results'][0]);
        self::assertSame(['name' => '测试任务'], $first['results'][0]['payload']);

        $this->record(244);
        $ids = array_column($first['results'], 'task_id');
        $page = $first;
        while ($page['has_more']) {
            $page = $this->tasks->history(20, $page['next_cursor']);
            array_push($ids, ...array_column($page['results'], 'task_id'));
        }
        self::assertCount(226, $ids);
        self::assertCount(226, array_unique($ids));
        self::assertContains($this->id(1), $ids);
        self::assertNotContains($this->id(244), $ids);
        $filtered = $this->tasks->history(20, null, 'failed');
        self::assertSame(1, $filtered['total']);
        self::assertSame([$this->id(242)], array_column($filtered['results'], 'task_id'));
        self::assertSame([$this->id(241)], array_column($filtered['active'], 'task_id'));
    }

    public function testSummaryIndexIsSharedRebuiltForLegacyTasksAndDoesNotReadEveryStateForListing(): void
    {
        $this->record(1);
        unlink($this->directory.'/index.json');
        $other = new TaskStore($this->directory);
        self::assertCount(1, $other->summaries());
        $this->record(2);
        self::assertCount(2, $other->summaries());
        $path = $this->store->taskDirectory($this->id(1)).'/state.json';
        $original = file_get_contents($path);
        file_put_contents($path, 'invalid state that a history page must not open');
        self::assertSame(2, $this->tasks->history()['total']);
        file_put_contents($path, $original);
        file_put_contents($this->directory.'/index.json', 'broken index');
        self::assertSame(2, $this->tasks->history()['total']);
        file_put_contents($this->directory.'/.index-dirty', '1');
        self::assertCount(2, $other->summaries());
        self::assertFileDoesNotExist($this->directory.'/.index-dirty');
    }

    public function testCleanupRemovesAllTerminalHistoryAndPreservesActiveFamilies(): void
    {
        $this->record(1);
        $this->record(2, 'cancelled', $this->id(1));
        $this->record(3, 'failed');
        $this->record(4, 'blocked');
        $this->record(5, 'running');
        $this->record(6);
        $this->record(7, 'running', $this->id(6));
        $this->record(8);
        file_put_contents($this->store->artifactsPath($this->id(8)).'/package.zip', 'download package');
        $this->record(9, 'pending');
        $this->record(10, 'failed');
        $this->store->writePayload($this->id(10), ['source_task_id' => $this->id(8)]);
        $this->record(11);
        $external = $this->directory.'/backup.zip';
        file_put_contents($external, 'user backup');
        $this->store->writeResult($this->id(11), ['backup' => $external]);
        $this->record(12, 'cancelled');
        $this->record(13, 'succeeded', null, 'cms.seo.push');
        $this->record(14, 'failed', $this->id(5));
        $this->record(15, 'blocked', $this->id(9));
        // 刚结束的任务也应清理，不再设日期门槛。
        $state = $this->store->readState($this->id(12));
        $state['finished_at'] = gmdate('c');
        $this->store->writeState($this->id(12), $state);

        $result = $this->cleaner->clean();
        $removed = array_map($this->id(...), [1, 2, 3, 4, 8, 10, 11, 12]);
        self::assertSame($removed, $result['removed_task_ids']);
        self::assertSame(7, $result['root_count']);
        self::assertSame(8, $result['task_count']);
        self::assertSame(3, $result['protected_count']);
        foreach ($removed as $id) {
            self::assertDirectoryDoesNotExist($this->store->taskDirectory($id));
        }
        foreach ([5, 6, 7, 9, 13, 14, 15] as $number) {
            self::assertFileExists($this->store->taskDirectory($this->id($number)).'/state.json');
        }
        self::assertSame('user backup', file_get_contents($external));
        self::assertCount(7, $this->store->summaries());
    }

    public function testCleanupReadsLatestStateAndCanBeRepeated(): void
    {
        $this->record(1, 'failed');
        $this->record(2, 'succeeded', $this->id(1));
        $state = $this->store->readState($this->id(1));
        $state['status'] = 'pending';
        $this->store->writeState($this->id(1), $state);
        self::assertSame([], $this->cleaner->clean()['removed_task_ids']);
        self::assertCount(2, $this->store->summaries());
        $state['status'] = 'failed';
        $this->store->writeState($this->id(1), $state);
        self::assertSame(2, $this->cleaner->clean()['task_count']);
        self::assertSame(0, $this->cleaner->clean()['task_count']);
        self::assertSame([], $this->store->summaries());
    }

    public function testCleanupNeverFollowsArtifactSymlinks(): void
    {
        $this->record(1);
        rmdir($this->store->artifactsPath($this->id(1)));
        $external = $this->directory.'/external';
        mkdir($external);
        file_put_contents($external.'/keep.txt', 'user asset');
        symlink($external, $this->store->taskDirectory($this->id(1)).'/artifacts');
        self::assertSame(1, $this->cleaner->clean()['task_count']);
        self::assertDirectoryDoesNotExist($this->store->taskDirectory($this->id(1)));
        self::assertSame('user asset', file_get_contents($external.'/keep.txt'));
    }

    public function testInvalidCursorIsRejected(): void
    {
        try {
            $this->tasks->history(20, 'not-a-cursor');
            self::fail('Invalid cursor must be rejected.');
        } catch (TaskException $exception) {
            self::assertSame('invalid_task_cursor', $exception->errorCode);
        }
    }

    private function id(int $number): string
    {
        return sprintf('%08d-0000-4000-8000-%012d', $number, $number);
    }

    private function record(int $number, string $status = 'succeeded', ?string $parent = null, string $type = TaskManager::ADDON_INSTALL): void
    {
        $at = gmdate('c', strtotime('2020-01-01') + $number);
        $this->store->writeState($this->id($number), [
            'task_id' => $this->id($number), 'type' => $type, 'status' => $status,
            'created_at' => $at, 'updated_at' => $at, 'finished_at' => $at,
            'parent_task_id' => $parent, 'attempt' => 1, 'max_attempts' => 2,
        ]);
        $this->store->writePayload($this->id($number), ['name' => '测试任务', 'secret' => 'never in summary']);
    }
}
