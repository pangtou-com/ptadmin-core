<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PTAdmin\Core\Http\Responses\AdminResponse;
use PTAdmin\Notice\NoticeInboxService;
use PTAdmin\Notice\NoticeReceiver;

final class NoticeInboxController extends Controller
{
    public function __construct(private NoticeInboxService $inbox)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', 'all');
        if (!in_array($status, ['all', 'unread'], true)) {
            return AdminResponse::error(42200, '站内信状态筛选无效。', ['field' => 'status'], 422);
        }
        $page = max(1, $request->integer('page', 1));
        $limit = min(100, max(1, $request->integer('limit', 20)));

        return AdminResponse::success('', $this->inbox->paginate($this->receiver($request), $status, $page, $limit));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return AdminResponse::success('', ['unread' => $this->inbox->unreadCount($this->receiver($request))]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $result = $this->inbox->markRead($this->receiver($request), $id);
        if ($result === null) {
            return AdminResponse::error(40400, '站内信不存在。', null, 404);
        }

        return AdminResponse::success('', $result);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        return AdminResponse::success('', $this->inbox->markAllRead($this->receiver($request)));
    }

    private function receiver(Request $request): NoticeReceiver
    {
        $session = (array) $request->attributes->get('ptadmin.session', []);

        return NoticeReceiver::admin((int) ($session['subject_id'] ?? 0));
    }
}
