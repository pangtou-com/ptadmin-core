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
use PTAdmin\Cloud\Contracts\CloudClientInterface;
use PTAdmin\Cloud\Contracts\CloudSessionStoreInterface;
use PTAdmin\Cloud\FounderAccess;
use PTAdmin\Core\Http\Responses\AdminResponse;

final class CloudController extends Controller
{
    public function __construct(
        private CloudClientInterface $client,
        private CloudSessionStoreInterface $sessions,
        private FounderAccess $founders,
        private CacheRepository $cache,
    ) {
    }

    public function session(Request $request): JsonResponse
    {
        try {
            $this->founders->assert($request);
            $session = $this->sessions->get();
            return AdminResponse::success('', ['authenticated' => $session !== null, 'user' => $this->user($session)]);
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
            $redirectUri = (string) config('ptadmin.cloud.redirect_uri', '');
            if ($redirectUri === '') {
                throw new CloudException('云市场授权回调地址尚未配置。', 'cloud_redirect_uri_missing');
            }
            $session = (array) $request->attributes->get('ptadmin.session', []);
            $this->cache->put('ptadmin.cloud.oauth.'.$state, [
                'verifier' => $verifier,
                'redirect_uri' => $redirectUri,
                'admin_id' => (string) ($session['subject_id'] ?? ''),
            ], now()->addMinutes(10));
            return AdminResponse::success('', ['authorization_url' => $this->client->authorizationUrl([
                'client_id' => (string) config('ptadmin.cloud.client_id', ''),
                'redirect_uri' => $redirectUri,
                'state' => $state,
                'code_challenge' => $this->challenge($verifier),
                'code_challenge_method' => 'S256',
            ])]);
        } catch (CloudException $exception) {
            return $this->error($exception);
        }
    }

    public function callback(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $state = (string) $request->query('state', '');
            $code = (string) $request->query('code', '');
            $pending = $state !== '' ? $this->cache->pull('ptadmin.cloud.oauth.'.$state) : null;
            if ($code === '' || !is_array($pending) || !is_string($pending['verifier'] ?? null)) {
                throw new CloudException('云市场授权回调无效或已过期。', 'cloud_oauth_state_invalid');
            }
            $admin = Admin::query()->find($pending['admin_id'] ?? null);
            if (!$admin instanceof Admin || !(bool) $admin->getAttribute('is_founder')) {
                throw new CloudException('云市场授权发起人已无权继续操作。', 'founder_required');
            }
            $session = $this->client->exchangeAuthorizationCode($code, $pending['verifier'], (string) ($pending['redirect_uri'] ?? ''));
            if (!is_string($session['access_token'] ?? null) || $session['access_token'] === '') {
                throw new CloudException('平台未返回有效会话。', 'cloud_session_invalid');
            }
            $this->sessions->put($session);
            $redirect = (string) config('ptadmin.cloud.callback_redirect', '');
            if ($redirect === '') {
                $redirect = '/'.trim((string) config('ptadmin.web_prefix', 'admin'), '/').'/cloud/market';
            }
            return redirect()->to($redirect);
        } catch (CloudException $exception) {
            return $this->error($exception);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $this->founders->assert($request);
            $session = $this->sessions->get();
            try {
                if (is_string($session['access_token'] ?? null) && $session['access_token'] !== '') {
                    $this->client->logout($session['access_token']);
                }
            } finally {
                $this->sessions->forget();
            }
            return AdminResponse::success('云市场已退出。');
        } catch (CloudException $exception) {
            return $this->error($exception);
        }
    }

    public function catalog(Request $request): JsonResponse
    {
        try {
            $this->founders->assert($request);
            return AdminResponse::success('', $this->client->catalog($this->token(), $request->query()));
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

    /** @param array<string, mixed>|null $session */
    private function user(?array $session): ?array
    {
        $user = $session['user'] ?? null;
        return is_array($user) ? $user : null;
    }

    private function token(): string
    {
        $token = $this->sessions->get()['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new CloudException('请先完成云市场浏览器授权。', 'cloud_session_required');
        }
        return $token;
    }

    private function randomToken(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function error(CloudException $exception): JsonResponse
    {
        $status = match ($exception->errorCode) {
            'unauthorized' => 401,
            'founder_required' => 403,
            'cloud_session_required', 'cloud_oauth_state_invalid' => 422,
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
        ], $status);
    }
}
