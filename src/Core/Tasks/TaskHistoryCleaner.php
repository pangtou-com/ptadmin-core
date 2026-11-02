<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tasks;

/** 显式确认后清理全部维护历史，正在执行的任务组整体保留。 */
final class TaskHistoryCleaner
{
    public function __construct(private readonly TaskStore $store)
    {
    }

    /** @return array{root_count: int, task_count: int, protected_count: int, removed_task_ids: list<string>} */
    public function clean(): array
    {
        return $this->store->withCreationLock(function (): array {
            $plan = $this->plan();
            $this->store->removeHistory($plan['removed_task_ids']);
            return $plan;
        });
    }

    /** @return array{root_count: int, task_count: int, protected_count: int, removed_task_ids: list<string>} */
    private function plan(): array
    {
        $summaries = $this->store->summaries();
        $families = [];
        foreach ($summaries as $id => $summary) {
            $root = $id;
            $seen = [];
            while (!empty($summaries[$root]['parent_task_id'])) {
                if (isset($seen[$root]) || !isset($summaries[$summaries[$root]['parent_task_id']])) {
                    continue 2;
                }
                $seen[$root] = true;
                $root = $summaries[$root]['parent_task_id'];
            }
            $families[$root][] = $id;
        }
        $ids = [];
        $rootCount = 0;
        $protectedCount = 0;
        foreach ($families as $root => $members) {
            try {
                TaskManager::permissionFor($summaries[$root]['type']);
            } catch (TaskException) {
                continue;
            }
            foreach ($members as $id) {
                if (!in_array($summaries[$id]['status'], ['succeeded', 'failed', 'blocked', 'cancelled'], true)) {
                    $protectedCount++;
                    continue 2;
                }
            }
            $rootCount++;
            array_push($ids, ...$members);
        }
        sort($ids);
        return [
            'root_count' => $rootCount,
            'task_count' => count($ids),
            'protected_count' => $protectedCount,
            'removed_task_ids' => $ids,
        ];
    }
}
