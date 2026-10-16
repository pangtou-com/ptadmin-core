<?php

declare(strict_types=1);

namespace PTAdmin\Cloud\Http\Controllers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PTAdmin\Admin\Models\Admin;
use PTAdmin\Cloud\CloudException;
use PTAdmin\Cloud\CloudSessionManager;
use PTAdmin\Cloud\CloudProtocol;
use Illuminate\Support\Facades\Cache;
use PTAdmin\Cloud\Contracts\CloudClientInterface;
use PTAdmin\Cloud\Contracts\CloudCatalogInterface;
use PTAdmin\Cloud\FounderAccess;
use PTAdmin\Core\Http\Responses\AdminResponse;
use PTAdmin\Core\Auth\Session\SessionManager;

final class CloudController extends Controller
{
    public function __construct(
        private CloudClientInterface $client,
        private FounderAccess $founders,
        private CacheRepository $cache,
        private CloudSessionManager $manager,
    ) {
    }

    public function session(Request $request): JsonResponse
    {
        try {
            $this->founders->assert($request);
            return AdminResponse::success('', $this->manager->summary())->header('Cache-Control', 'no-store');
        } catch (CloudException $exception) {
            return $this->error($exception);
        }
    }

    public function authorize(Request $request): JsonResponse
    {
        try {
            $this->founders->assert($request);
            $state = $this->randomToken(32);
            $verifier = $this->randomToken(48);
            $redirectUri = route('ptadmin.cloud.session.callback');
            $browser = $this->randomToken(32);
            $session = (array) $request->attributes->get('ptadmin.session', []);
            $this->cache->put('ptadmin.cloud.oauth.'.$state, [
                'verifier' => $verifier,
                'redirect_uri' => $redirectUri,
                'admin_id' => (string) ($session['subject_id'] ?? ''),
                'browser_hash' => hash('sha256', $browser),
                'session_id' => $session['id'] ?? null,
                'subject_type' => $session['subject_type'],
                'guard_name' => $session['guard_name'],
            ], now()->addMinutes(10));
            return AdminResponse::success('', ['authorization_url' => $this->client->authorizationUrl([
                'redirect_uri' => $redirectUri,
                'state' => $state,
                'code_challenge' => CloudProtocol::challenge($verifier),
                'code_challenge_method' => 'S256',
            ])])->header('Cache-Control', 'no-store')->cookie('ptadmin_cloud_'.hash('sha256', $state), $browser, 10, '/', null, $request->isSecure(), true, false, 'lax');
        } catch (CloudException $exception) {
            return $this->error($exception);
        }
    }

    public function callback(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $state = $request->query('state', '');
            $code = $request->query('code', '');
            if (!is_string($state) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $state) !== 1 || !is_string($code) || strlen($code) > 128) {
                throw new CloudException('云市场授权回调参数无效。', 'cloud_oauth_state_invalid');
            }
            $pending = Cache::lock('ptadmin.cloud.callback.'.hash('sha256', $state), 10)->block(3, function () use ($state, $request): ?array {
                $pending = $this->cache->get('ptadmin.cloud.oauth.'.$state);
                $browser = $request->cookie('ptadmin_cloud_'.hash('sha256', $state), '');
                if (!is_array($pending) || !is_string($browser) || !hash_equals($pending['browser_hash'], hash('sha256', $browser))) {
                    return null;
                }
                $this->cache->forget('ptadmin.cloud.oauth.'.$state);
                return $pending;
            });
            if (!is_array($pending) || !is_string($pending['verifier'] ?? null)) {
                throw new CloudException('云市场授权回调无效或已过期。', 'cloud_oauth_state_invalid');
            }
            if ($request->query('error') === 'access_denied') {
                throw new CloudException('你已取消本次平台授权。', 'cloud_authorization_denied');
            }
            if ($code === '') {
                throw new CloudException('平台未返回授权码。', 'cloud_oauth_state_invalid');
            }
            $admin = Admin::query()->find($pending['admin_id'] ?? null);
            if (!$admin instanceof Admin || !(bool) $admin->getAttribute('is_founder') || !(bool) $admin->getAttribute('status')) {
                throw new CloudException('云市场授权发起人已无权继续操作。', 'founder_required');
            }
            $origin = collect(app(SessionManager::class)->forSubject($pending['subject_type'], $admin->getKey(), $pending['guard_name']))->firstWhere('id', $pending['session_id']);
            $now = gmdate('Y-m-d H:i:s');
            if ($origin === null || $origin['revoked_at'] !== null || $origin['expires_at'] <= $now || $origin['absolute_expires_at'] <= $now) {
                throw new CloudException('发起授权的管理员会话已失效，请重新登录。', 'unauthorized');
            }
            $session = $this->client->exchangeAuthorizationCode($code, $pending['verifier'], (string) ($pending['redirect_uri'] ?? ''));
            $this->manager->store($session);
            $redirect = (string) config('ptadmin.cloud.callback_redirect', '');
            if ($redirect === '') {
                $redirect = '/'.trim((string) config('ptadmin.web_prefix', 'admin'), '/').'/cloud/market';
            }
            return redirect()->to($redirect)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')->withoutCookie('ptadmin_cloud_'.hash('sha256', $state));
        } catch (CloudException $exception) {
            return $this->error($exception);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $this->founders->assert($request);
            $this->manager->logout();
            return AdminResponse::success('云市场已退出。');
        } catch (CloudException $exception) {
            return $this->error($exception);
        }
    }

    public function catalog(Request $request, CloudCatalogInterface $catalog): JsonResponse
    {
        try {
            $this->founders->assert($request);
            return AdminResponse::success('', $catalog->catalog($request->only(['product_type', 'keyword', 'category', 'page', 'per_page'])));
        } catch (CloudException $exception) {
            return $this->error($exception);
        }
    }

    public function order(Request $request): JsonResponse
    {
        try {
            $this->founders->assert($request);
            return AdminResponse::success('', $this->client->createOrder($this->token(), $request->all()));
        } catch (CloudException $exception) {
            return $this->error($exception);
        }
    }

    public function showOrder(Request $request, string $orderId): JsonResponse
    {
        try {
            $this->founders->assert($request);
            return AdminResponse::success('', $this->client->order($this->token(), $orderId));
        } catch (CloudException $exception) {
            return $this->error($exception);
        }
    }

    public function license(Request $request): JsonResponse
    {
        try {
            $this->founders->assert($request);
            return AdminResponse::success('', $this->client->previewLicense($this->token(), $request->all()));
        } catch (CloudException $exception) {
            return $this->error($exception);
        }
    }

    public function migrateIp(Request $request): JsonResponse
    {
        try {
            $this->founders->assert($request);
            return AdminResponse::success('', $this->client->migrateIp($this->token(), $request->all()));
        } catch (CloudException $exception) {
            return $this->error($exception);
        }
    }

    public function requestMigration(Request $request): JsonResponse
    {
        try {
            $this->founders->assert($request);
            return AdminResponse::success('', $this->client->requestInstanceMigration($this->token(), $request->all()));
        } catch (CloudException $exception) {
            return $this->error($exception);
        }
    }

    public function products(Request $request): JsonResponse
    {
        try {
            $this->founders->assert($request);
            return AdminResponse::success('', $this->client->products($this->token(), $request->only(['page'])));
        } catch (CloudException $exception) {
            return $this->error($exception);
        }
    }

    public function submitRelease(Request $request, string $type): JsonResponse
    {
        try {
            $this->founders->assert($request);
            return AdminResponse::success('', $this->client->submitRelease($this->token(), $type, $request->only(['product_id', 'changelog', 'name', 'content_base64', 'source_package'])));
        } catch (CloudException $exception) {
            return $this->error($exception);
        }
    }

    private function token(): string
    {
        return $this->manager->token();
    }

    private function randomToken(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function error(CloudException $exception): JsonResponse
    {
        $status = match ($exception->errorCode) {
            'unauthorized' => 401,
            'founder_required', 'forbidden' => 403,
            'cloud_session_required', 'cloud_oauth_state_invalid', 'cloud_authorization_denied', 'invalid_request' => 422,
            default => 503,
        };
        $code = match ($status) {
            401 => 40100,
            403 => 40300,
            503 => 50300,
            default => 42200,
        };
        return AdminResponse::error($code, $exception->getMessage(), [
            'error_code' => $exception->errorCode,
            'details' => $exception->details,
        ], $status)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
