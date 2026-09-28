<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

final class NoticeInboxService
{
    public function __construct(private ConnectionInterface $database)
    {
    }

    /** @return array{total:int,results:list<array<string,mixed>>} */
    public function paginate(NoticeReceiver $receiver, string $status = 'all', int $page = 1, int $limit = 20): array
    {
        $query = $this->activeMessages($receiver);
        if ($status === 'unread') {
            $query->whereNull('read_at');
        }

        $total = (int) (clone $query)->count();
        $results = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage(max(1, $page), max(1, $limit))
            ->get()
            ->map(fn (object $message): array => $this->messagePayload($message))
            ->values()
            ->all();

        return ['total' => $total, 'results' => $results];
    }

    public function unreadCount(NoticeReceiver $receiver): int
    {
        return (int) $this->activeMessages($receiver)->whereNull('read_at')->count();
    }

    /** @return array{id:int,read_at:int}|null */
    public function markRead(NoticeReceiver $receiver, int $messageId): ?array
    {
        $message = $this->activeMessages($receiver)->where('id', $messageId)->first(['id', 'read_at']);
        if ($message === null) {
            return null;
        }

        $readAt = $message->read_at !== null ? (int) $message->read_at : time();
        if ($message->read_at === null) {
            $this->database->table('notice_messages')->where('id', $messageId)->update([
                'read_at' => $readAt,
                'updated_at' => $readAt,
            ]);
        }

        return ['id' => (int) $message->id, 'read_at' => $readAt];
    }

    /** @return array{updated:int,read_at:int} */
    public function markAllRead(NoticeReceiver $receiver): array
    {
        $readAt = time();
        $updated = $this->activeMessages($receiver)
            ->whereNull('read_at')
            ->update(['read_at' => $readAt, 'updated_at' => $readAt]);

        return ['updated' => $updated, 'read_at' => $readAt];
    }

    private function activeMessages(NoticeReceiver $receiver): Builder
    {
        $now = time();

        return $this->database->table('notice_messages')
            ->where('receiver_type', $receiver->type)
            ->where('receiver_id', $receiver->id)
            ->whereNull('archived_at')
            ->whereNull('deleted_at')
            ->where(static function (Builder $query) use ($now): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            });
    }

    /** @return array<string,mixed> */
    private function messagePayload(object $message): array
    {
        $data = $this->decodeData($message->data ?? null);
        $action = null;
        if (($message->action_type ?? null) === 'url' && is_string($message->action_url) && $message->action_url !== '') {
            $action = [
                'type' => 'url',
                'target' => $message->action_url,
                'label' => is_string($data['action_label'] ?? null) ? $data['action_label'] : '查看详情',
            ];
        }

        return [
            'id' => (int) $message->id,
            'category' => (string) $message->category,
            'level' => (string) $message->level,
            'title' => (string) $message->title,
            'content' => $message->content !== null ? (string) $message->content : null,
            'action' => $action,
            'read_at' => $message->read_at !== null ? (int) $message->read_at : null,
            'created_at' => (int) $message->created_at,
        ];
    }

    /** @return array<string,mixed> */
    private function decodeData(mixed $value): array
    {
        if (is_array($value) && !array_is_list($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }
}
