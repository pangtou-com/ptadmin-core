<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PTAdmin\Admin\Auth\AdminAuthenticator;
use PTAdmin\Admin\Auth\AuthorizationService;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Addon\Frontend\FrontendRegistry;
use PTAdmin\Admin\Frontend\ApplicationFrontendRegistry;
use PTAdmin\Core\Auth\Exceptions\InvalidSessionException;
use PTAdmin\Core\Auth\Session\SessionManager;
use PTAdmin\Core\Http\Responses\AdminResponse;

final class SessionController extends Controller
{
    public function __construct(
        private SessionManager $sessions,
        private AdminAuthenticator $authenticator,
        private AuthorizationService $authorization,
        private FrontendRegistry $addonFrontends,
        private ApplicationFrontendRegistry $applicationFrontends,
    )
    {
    }

    public function show(Request $request): JsonResponse
    {
        /** @var array{id:string, subject_type:string, subject_id:string, guard_name:string, metadata:array<string,mixed>, expires_at:string, absolute_expires_at:string} $session */
        $session = $request->attributes->get('ptadmin.session');
        $admin = Admin::query()->find($session['subject_id']);
        if (!$admin instanceof Admin || 'admin' !== $session['guard_name']) {
            return AdminResponse::error(40102, '管理员账号不可用，请重新登录。', null, 401);
        }

        $permissions = $this->authorization->permissions($admin);
        $menus = $this->authorization->menus($admin);
        $plugins = $this->addonFrontends->resolve($menus);
        $applications = $this->applicationFrontends->resolve($menus);
        $modules = array_merge($applications['modules'], $plugins['plugins']);
        $identity = $this->authorization->identity($admin)->attributes;

        return AdminResponse::success('', [
            'user' => $this->authenticator->userPayload($admin),
            'permissions' => $permissions,
            'roles' => $this->authorization->roles($admin),
            'menus' => $menus,
            'theme' => (array) config('ptadmin.theme', []),
            'layout' => (array) config('ptadmin.layout', []),
            'plugins' => $plugins['plugins'],
            'modules' => $modules,
            'session' => [
                'expires_at' => $session['expires_at'],
                'absolute_expires_at' => $session['absolute_expires_at'],
            ],
            'generation' => hash('sha256', (string) $session['id'].($identity === [] ? '' : json_encode($identity, JSON_THROW_ON_ERROR))),
            ...($identity === [] ? [] : ['authorization' => $identity]),
            'features' => [
                'task_center' => true,
                'plugin_runtime' => true,
                'application_runtime' => true,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function renew(Request $request): JsonResponse
    {
        try {
            $renewed = $this->sessions->renew((string) $request->bearerToken());
        } catch (InvalidSessionException $exception) {
            return AdminResponse::error(40101, $exception->getMessage(), null, 401);
        }

        return AdminResponse::success('', $renewed)->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request): JsonResponse
    {
        $this->sessions->revoke((string) $request->bearerToken());

        return AdminResponse::success('');
    }
}
