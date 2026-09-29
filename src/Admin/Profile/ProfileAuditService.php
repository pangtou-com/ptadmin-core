<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Profile;

use Illuminate\Database\ConnectionInterface;

final class ProfileAuditService
{
    public function __construct(private ConnectionInterface $database)
    {
    }

    /** @return array{total:int,results:list<array<string,mixed>>,page:int,limit:int} */
    public function loginLogs(int $adminId, int $page = 1, int $limit = 20): array
    {
        $query = $this->database->table('admin_login_logs')
            ->where('admin_id', $adminId)
            ->orderByDesc('id');

        return $this->paginate($query, [
            'id',
            'username',
            'successful',
            'failure_reason',
            'ip_address',
            'user_agent',
            'created_at',
        ], $page, $limit, static function (object $row): array {
            return [
                'id' => (int) $row->id,
                'username' => (string) $row->username,
                'successful' => (bool) $row->successful,
                'failure_reason' => (string) $row->failure_reason,
                'ip_address' => (string) $row->ip_address,
                'user_agent' => (string) $row->user_agent,
                'created_at' => (string) $row->created_at,
            ];
        });
    }

    /** @return array{total:int,results:list<array<string,mixed>>,page:int,limit:int} */
    public function operationLogs(int $adminId, int $page = 1, int $limit = 20): array
    {
        $query = $this->database->table('admin_operation_records')
            ->where('admin_id', $adminId)
            ->orderByDesc('id');

        return $this->paginate($query, [
            'id',
            'request_id',
            'resource_key',
            'action',
            'method',
            'path',
            'status_code',
            'response_time_ms',
            'successful',
            'ip_address',
            'created_at',
        ], $page, $limit, static function (object $row): array {
            return [
                'id' => (int) $row->id,
                'request_id' => (string) $row->request_id,
                'resource_key' => (string) $row->resource_key,
                'action' => (string) $row->action,
                'method' => (string) $row->method,
                'path' => (string) $row->path,
                'status_code' => (int) $row->status_code,
                'response_time_ms' => (int) $row->response_time_ms,
                'successful' => (bool) $row->successful,
                'ip_address' => (string) $row->ip_address,
                'created_at' => (string) $row->created_at,
            ];
        });
    }

    /** @return array<string,mixed>|null */
    public function operation(int $adminId, int $id): ?array
    {
        $row = $this->database->table('admin_operation_records')
            ->where('admin_id', $adminId)
            ->where('id', $id)
            ->first();
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'request_id' => (string) $row->request_id,
            'resource_key' => (string) $row->resource_key,
            'action' => (string) $row->action,
            'method' => (string) $row->method,
            'path' => (string) $row->path,
            'status_code' => (int) $row->status_code,
            'response_time_ms' => (int) $row->response_time_ms,
            'request_payload' => $this->jsonValue((string) ($row->request_payload ?? '')),
            'successful' => (bool) $row->successful,
            'ip_address' => (string) $row->ip_address,
            'created_at' => (string) $row->created_at,
        ];
    }

    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @param list<string> $columns
     * @param callable(object):array<string,mixed> $transform
     * @return array{total:int,results:list<array<string,mixed>>,page:int,limit:int}
     */
    private function paginate($query, array $columns, int $page, int $limit, callable $transform): array
    {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));
        $total = (int) (clone $query)->count();
        $results = $query->forPage($page, $limit)
            ->get($columns)
            ->map($transform)
            ->values()
            ->all();

        return [
            'total' => $total,
            'results' => $results,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /** @return array<string, mixed>|list<mixed>|null */
    private function jsonValue(string $value): array|null
    {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
