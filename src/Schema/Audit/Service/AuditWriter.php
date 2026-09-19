<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Audit\Service;

use Illuminate\Support\Facades\DB;
use PTAdmin\Schema\Audit\Redactor;
use PTAdmin\Schema\Support\Payload;

/**
 * Schema 模块审计记录写入和分页查询服务。
 */
final class AuditWriter
{
    /**
     * 写入一条已脱敏的审计事件；编码失败时直接抛错，避免产生不可解析的半条记录。
     *
     * @param string $action
     * @param array<string, mixed> $payload
     * @param \PTAdmin\Schema\Definition\Schema|null $schema
     * @throws \JsonException
     */
    public function write(string $action, array $payload = [], ?\PTAdmin\Schema\Definition\Schema $schema = null): void
    {
        $action = trim($action);
        if ($action === '') {
            throw new \InvalidArgumentException('audit action is required');
        }
        $safe = Redactor::redact($payload, $schema);
        $columns = ['kind', 'category', 'action', 'status', 'resource_key', 'record_id', 'subject_type', 'subject_id', 'actor_type', 'actor_id', 'request_id', 'trace_id', 'remote_ip', 'user_agent', 'payload_json', 'occurred_at'];
        $values = [];
        foreach (array_slice($columns, 0, -2) as $column) {
            $values[$column] = (string) ($safe[$column] ?? '');
        }
        $values['action'] = $action;
        $values['payload_json'] = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $values['occurred_at'] = now();
        DB::table('schema_audit_events')->insert($values);
    }

    /**
     * 按协议允许的筛选字段分页返回审计事件，payload_json 会转换为 payload 数组。
     *
     * @param array<string, mixed>|object $query
     * @return array{results:list<array<string, mixed>>,total:int,page:int,limit:int}
     */
    public function list(array|object $query = []): array
    {
        $query = Payload::array($query);
        $builder = DB::table('schema_audit_events');
        foreach (['kind', 'category', 'action', 'status', 'resource_key', 'record_id', 'subject_type', 'subject_id', 'actor_type', 'actor_id', 'request_id', 'trace_id'] as $field) {
            if (trim((string) ($query[$field] ?? '')) !== '') {
                $builder->where($field, trim((string) $query[$field]));
            }
        }
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = max(1, min(100, (int) ($query['limit'] ?? 20)));
        $total = (clone $builder)->count();
        $items = $builder->orderByDesc('occurred_at')->orderByDesc('id')->forPage($page, $limit)->get()->map(static function ($row): array {
            $item = (array) $row;
            $item['payload'] = json_decode((string) ($item['payload_json'] ?? '{}'), true) ?: [];
            unset($item['payload_json']);
            return $item;
        })->all();
        return ['results' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }
}
