<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Core\Auth\Session\SessionManager;
use PTAdmin\Core\Http\Responses\AdminResponse;

final class AdminSessionController extends Controller
{
    public function __construct(private SessionManager $sessions)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $session = (array) $request->attributes->get('ptadmin.session', []);

        return AdminResponse::success('', [
            'results' => $this->sessions->forSubject(Admin::class, (string) ($session['subject_id'] ?? ''), 'admin'),
        ]);
    }

    public function revoke(Request $request, string $sessionId): JsonResponse
    {
        $session = (array) $request->attributes->get('ptadmin.session', []);
        $revoked = $this->sessions->revokeByIdForSubject($sessionId, Admin::class, (string) ($session['subject_id'] ?? ''), 'admin');
        if (!$revoked) {
            return AdminResponse::error(40400, 'The session was not found.', null, 404);
        }

        return AdminResponse::success('');
    }
}
