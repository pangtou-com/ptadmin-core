<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Captcha;

use Illuminate\Cache\{ArrayStore, Repository};
use Illuminate\Support\Carbon;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Runtime\AddonRegistration;
use PTAdmin\Captcha\{CaptchaGateway, CaptchaInterface, CaptchaRegistry, ChallengeStatus, ChallengeType};
use PTAdmin\Captcha\Data\{ChallengeCreateRequest, ChallengeCreateResult, ChallengeRefreshRequest, ChallengeVerifyRequest, ChallengeVerifyResult};
use PTAdmin\Captcha\Protocol\CaptchaDefinition;
use PTAdmin\Core\Tests\Fixtures\ServiceProtocol\ProtocolTestCase;
use RuntimeException;

final class CaptchaGatewayTest extends ProtocolTestCase
{
    private CaptchaRegistry $registry;
    private CaptchaGateway $gateway;
    private AddonRegistration $registration;
    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registration = new AddonRegistration();
        $this->registry = new CaptchaRegistry($this->container, $this->availability, $this->registration);
        $this->cache = new Repository(new ArrayStore());
        $this->gateway = new CaptchaGateway($this->registry, $this->cache);
        $this->activate('captcha-test');
        $this->registration->run('captcha-test', fn () => $this->registry->register('captcha-test', TestCaptchaProvider::class));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testPublicDataNeverSerializesPrivateAnswers(): void
    {
        $result = (new TestCaptchaProvider())->create(new ChallengeCreateRequest(['scene' => 'sms']));
        self::assertSame(['answer' => 'secret-answer'], $result->privateState());
        self::assertArrayNotHasKey('private_state', $result->toArray());
        self::assertStringNotContainsString('secret-answer', json_encode($result));
        self::assertArrayNotHasKey('private_state', $this->create());
    }

    public function testSuccessAndFailureConsumeTheChallenge(): void
    {
        foreach (['secret-answer' => ChallengeStatus::PASSED, 'wrong' => ChallengeStatus::REJECTED] as $answer => $status) {
            $id = $this->create()['challenge_id'];
            self::assertSame($status, $this->gateway->verify($id, 'sms', 'session-a', ['answer' => $answer])->status());
            self::assertSame(ChallengeStatus::EXPIRED, $this->gateway->verify($id, 'sms', 'session-a', ['answer' => 'secret-answer'])->status());
        }
    }

    public function testContextAndExpiryAreCheckedBeforeProviderVerification(): void
    {
        $id = $this->create()['challenge_id'];
        self::assertSame(ChallengeStatus::REJECTED, $this->gateway->verify($id, 'login', 'session-a', ['answer' => 'secret-answer'])->status());
        self::assertSame(ChallengeStatus::REJECTED, $this->gateway->verify($id, 'sms', 'session-b', ['answer' => 'secret-answer'])->status());
        Carbon::setTestNow(now()->addSeconds(121));
        self::assertSame(ChallengeStatus::EXPIRED, $this->gateway->verify($id, 'sms', 'session-a', ['answer' => 'secret-answer'])->status());
        $this->assertProtocolError('context_invalid', fn () => $this->gateway->create('captcha-test', 'sms', ''));
    }

    public function testRefreshRevokesOldChallengeAndCannotBeReplayed(): void
    {
        $id = $this->create()['challenge_id'];
        $next = $this->gateway->refresh($id, 'sms', 'session-a');
        self::assertNotSame($id, $next['challenge_id']);
        self::assertSame(ChallengeStatus::EXPIRED, $this->gateway->verify($id, 'sms', 'session-a', ['answer' => 'secret-answer'])->status());
        $this->assertProtocolError('challenge_expired', fn () => $this->gateway->refresh($id, 'sms', 'session-a'));
        self::assertTrue($this->gateway->verify($next['challenge_id'], 'sms', 'session-a', ['answer' => 'secret-answer'])->passed());
    }

    public function testConcurrentVerifyAndRefreshCannotConsumeALockedChallenge(): void
    {
        $id = $this->create()['challenge_id'];
        $lock = $this->cache->getStore()->lock('ptadmin:captcha:'.hash('sha256', $id).':lock', 10);
        self::assertTrue($lock->get());
        try {
            self::assertSame(ChallengeStatus::LOCKED, $this->gateway->verify($id, 'sms', 'session-a', ['answer' => 'secret-answer'])->status());
            $this->assertProtocolError('challenge_locked', fn () => $this->gateway->refresh($id, 'sms', 'session-a'));
        } finally {
            $lock->release();
        }
        self::assertTrue($this->gateway->verify($id, 'sms', 'session-a', ['answer' => 'secret-answer'])->passed());
    }

    public function testDisabledPluginsCannotVerifyExistingChallenges(): void
    {
        $id = $this->create()['challenge_id'];
        $this->lifecycle->transition('captcha-test', 'inactive');
        self::assertSame([], $this->registry->definitions());
        $this->assertProtocolError('addon_unavailable', fn () => $this->gateway->verify($id, 'sms', 'session-a', ['answer' => 'secret-answer']));
        $this->assertProtocolError('addon_unavailable', fn () => $this->create());
    }

    public function testCapabilityRegistrationRollsBackWhenAnotherCommitFails(): void
    {
        $this->activate('rollback-test');
        try {
            $this->registration->run('rollback-test', function (): void {
                $this->registry->register('rollback-test', TestCaptchaProvider::class);
                $this->registration->stage('rollback-test', 'failure', static function (): void {
                    throw new RuntimeException('expected failure');
                }, static function (): void {});
            });
            self::fail('预期注册失败');
        } catch (RuntimeException $exception) {
            self::assertSame('expected failure', $exception->getMessage());
        }
        self::assertArrayNotHasKey('rollback-test', $this->registry->definitions());
        $this->expectException(AddonException::class);
        $this->registry->register('rollback-test', TestCaptchaProvider::class);
    }

    private function create(): array
    {
        return $this->gateway->create('captcha-test', 'sms', 'session-a');
    }
}

final class TestCaptchaProvider implements CaptchaInterface
{
    public function definition(): CaptchaDefinition
    {
        return CaptchaDefinition::make('test', ChallengeType::IMAGE_TEXT, ['create', 'verify', 'refresh']);
    }

    public function create(ChallengeCreateRequest $request): ChallengeCreateResult
    {
        return new ChallengeCreateResult([
            'challenge_id' => bin2hex(random_bytes(16)),
            'type' => ChallengeType::IMAGE_TEXT,
            'expires_at' => now()->addSeconds(120)->toAtomString(),
            'presentation' => ['image_url' => 'data:image/png;base64,fixture'],
            'private_state' => ['answer' => 'secret-answer'],
        ]);
    }

    public function verify(ChallengeVerifyRequest $request): ChallengeVerifyResult
    {
        return new ChallengeVerifyResult(['status' => ($request->response()['answer'] ?? null) === $request->privateState()['answer']
            ? ChallengeStatus::PASSED : ChallengeStatus::REJECTED]);
    }

    public function refresh(ChallengeRefreshRequest $request): ChallengeCreateResult
    {
        return $this->create(new ChallengeCreateRequest(['scene' => $request->scene()]));
    }
}
