<?php

declare(strict_types=1);

namespace PTAdmin\Captcha;

use Illuminate\Contracts\Cache\{LockProvider, Repository};
use PTAdmin\Captcha\Data\{ChallengeCreateRequest, ChallengeCreateResult, ChallengeRefreshRequest, ChallengeVerifyRequest, ChallengeVerifyResult};
use PTAdmin\Core\ServiceProtocol\ProtocolException;

/** 宿主业务入口：服务端保存答案，按场景和主体绑定，并在锁内单次消费。 */
final class CaptchaGateway
{
    public function __construct(private readonly CaptchaRegistry $registry, private readonly Repository $cache)
    {
    }

    public function create(string $addonCode, string $scene, string $subject): array
    {
        $this->assertContext($scene, $subject);
        $this->lockStore();
        $challenge = $this->registry->provider($addonCode)->create(new ChallengeCreateRequest(['scene' => $scene]));
        return $this->store($addonCode, $scene, $subject, $challenge);
    }

    private function store(string $addonCode, string $scene, string $subject, ChallengeCreateResult $challenge): array
    {
        $expiresAt = strtotime((string) $challenge->get('expires_at'));
        $ttl = $expiresAt === false ? 0 : $expiresAt - now()->getTimestamp();
        if ($ttl <= 0 || $ttl > 180 || $challenge->challengeId() === '' || $challenge->privateState() === []) {
            throw new ProtocolException('challenge_invalid', '验证码插件生成了无效挑战：'.$addonCode);
        }
        $stored = $this->cache->add($this->key($challenge->challengeId()), [
            'addon_code' => $addonCode,
            'scene' => $scene,
            'subject' => hash('sha256', $subject),
            'expires_at' => $expiresAt,
            'private_state' => $challenge->privateState(),
        ], $ttl);
        if (!$stored) {
            throw new ProtocolException('challenge_store_failed', '无法保存验证码挑战：'.$addonCode);
        }
        return $challenge->publicData();
    }

    public function verify(string $challengeId, string $scene, string $subject, array $response): ChallengeVerifyResult
    {
        $state = $this->consume($challengeId, $scene, $subject);
        if ($state instanceof ChallengeVerifyResult) {
            return $state;
        }
        return $this->registry->provider($state['addon_code'])->verify(new ChallengeVerifyRequest([
            'challenge_id' => $challengeId,
            'scene' => $scene,
            'response' => $response,
            'private_state' => $state['private_state'],
        ]));
    }

    public function refresh(string $challengeId, string $scene, string $subject): array
    {
        $state = $this->consume($challengeId, $scene, $subject);
        if ($state instanceof ChallengeVerifyResult) {
            throw new ProtocolException('challenge_'.$state->status(), '验证码刷新失败，请重新获取挑战。');
        }
        $challenge = $this->registry->provider($state['addon_code'])->refresh(new ChallengeRefreshRequest([
            'challenge_id' => $challengeId,
            'scene' => $scene,
            'private_state' => $state['private_state'],
        ]));
        if ($challenge->challengeId() === $challengeId) {
            throw new ProtocolException('challenge_invalid', '验证码刷新必须生成新的挑战标识。');
        }
        return $this->store($state['addon_code'], $scene, $subject, $challenge);
    }

    private function consume(string $challengeId, string $scene, string $subject): array|ChallengeVerifyResult
    {
        $this->assertContext($scene, $subject);
        if ($challengeId === '' || strlen($challengeId) > 200) {
            return new ChallengeVerifyResult(['status' => ChallengeStatus::PAYLOAD_INVALID]);
        }
        $key = $this->key($challengeId);
        $result = $this->lockStore()->lock($key.':lock', 10)->get(function () use ($key, $scene, $subject) {
            $state = $this->cache->get($key);
            if (!is_array($state) || $state['expires_at'] <= now()->getTimestamp()) {
                return new ChallengeVerifyResult(['status' => ChallengeStatus::EXPIRED]);
            }
            if ($state['scene'] !== $scene || !hash_equals($state['subject'], hash('sha256', $subject))) {
                return new ChallengeVerifyResult(['status' => ChallengeStatus::REJECTED, 'reason_code' => 'context_mismatch']);
            }
            // 删除失败时拒绝验证，不能让同一挑战在后续请求再次通过。
            if (!$this->cache->forget($key)) {
                throw new ProtocolException('challenge_store_failed', '无法消费验证码挑战。');
            }
            return $state;
        });
        return $result === false ? new ChallengeVerifyResult(['status' => ChallengeStatus::LOCKED]) : $result;
    }

    private function lockStore(): LockProvider
    {
        $store = $this->cache->getStore();
        if (!$store instanceof LockProvider) {
            throw new ProtocolException('cache_lock_unavailable', '验证码缓存必须支持原子锁。');
        }
        return $store;
    }

    private function key(string $id): string
    {
        return 'ptadmin:captcha:'.hash('sha256', $id);
    }

    private function assertContext(string $scene, string $subject): void
    {
        if (trim($scene) === '' || strlen($scene) > 100 || trim($subject) === '' || strlen($subject) > 1024) {
            throw new ProtocolException('context_invalid', '验证码必须绑定有效的业务场景和服务端主体。');
        }
    }
}
