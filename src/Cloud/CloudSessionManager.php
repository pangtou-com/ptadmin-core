<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

use Illuminate\Support\Facades\Cache;
use PTAdmin\Cloud\Contracts\CloudClientInterface;
use PTAdmin\Cloud\Contracts\CloudSessionStoreInterface;

final class CloudSessionManager
{
    public function __construct(private readonly CloudClientInterface $client, private readonly CloudSessionStoreInterface $sessions)
    {
    }

    public function token(): string
    {
        return Cache::lock('ptadmin.cloud.session.refresh', 90)->block(5, fn () => $this->activeToken());
    }

    /** 调用方持有会话锁，避免并发刷新消费同一凭据。 */
    private function activeToken(): string
    {
        $session = $this->sessions->get();
        if (!is_array($session) || !is_string($session['access_token'] ?? null)) {
            throw new CloudException('请先登录平台。', 'cloud_session_required');
        }
        if (($session['expires_at'] ?? 0) <= time() + 30) {
            if (!is_string($session['refresh_token'] ?? null)) {
                $this->sessions->forget();
                throw new CloudException('云市场会话已过期，请重新登录平台。', 'cloud_session_required');
            }
            try {
                $session = $this->client->refresh($session['refresh_token']);
                $this->persist($session);
            } catch (CloudException $exception) {
                if (in_array($exception->errorCode, ['unauthorized', 'forbidden'], true)) {
                    $this->sessions->forget();
                }
                throw $exception;
            }
        }
        return $session['access_token'];
    }

    public function store(array $session): void
    {
        $this->validateSession($session);
        Cache::lock('ptadmin.cloud.session.refresh', 90)->block(5, function () use ($session): void {
            $previous = $this->sessions->get();
            if (is_string($previous['access_token'] ?? null) && $previous['access_token'] !== ($session['access_token'] ?? null)) {
                $this->client->logout($previous['access_token']);
            }
            $this->persist($session);
        });
    }

    public function logout(): void
    {
        Cache::lock('ptadmin.cloud.session.refresh', 90)->block(5, function (): void {
            $session = $this->sessions->get();
            try {
                if (is_string($session['access_token'] ?? null)) {
                    $this->client->logout($session['access_token']);
                }
            } finally {
                $this->sessions->forget();
            }
        });
    }

    private function validateSession(array $session): void
    {
        if (!is_string($session['access_token'] ?? null) || !is_string($session['refresh_token'] ?? null)
            || !is_int($session['expires_at'] ?? null) || $session['expires_at'] <= time()) {
            throw new CloudException('平台未返回有效会话。', 'cloud_session_invalid');
        }
    }

    private function persist(array $session): void
    {
        $this->validateSession($session);
        $this->sessions->put($session);
    }

    public function summary(): array
    {
        return Cache::lock('ptadmin.cloud.session.refresh', 150)->block(5, function (): array {
            $disconnected = ['authenticated' => false, 'user' => null, 'authorization_expires_at' => null];
            if ($this->sessions->get() === null) {
                return $disconnected;
            }
            try {
                $summary = $this->client->session($this->activeToken());
                return ['authenticated' => true, 'user' => $summary['user'] ?? null,
                    'authorization_expires_at' => $summary['authorization_expires_at'] ?? null];
            } catch (CloudException $exception) {
                if (in_array($exception->errorCode, ['unauthorized', 'forbidden', 'cloud_session_required'], true)) {
                    $this->sessions->forget();
                    return $disconnected;
                }
                throw $exception;
            }
        });
    }
}
