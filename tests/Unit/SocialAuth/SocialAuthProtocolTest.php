<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\SocialAuth;

use PTAdmin\Core\Tests\Fixtures\ServiceProtocol\{ProtocolTestCase, FakeSocialAuthAdapter, RefreshableSocialAuthAdapter};
use PTAdmin\Core\ServiceProtocol\Secret;
use PTAdmin\SocialAuth\{SocialAuthRegistry, SocialAuthRegistration, SocialAuthGateway, SocialAuthDefinition,
    SocialAuthProfile, SocialAuthReference, SocialAuthRequirements, SocialAuthReadiness,
    AuthorizeRequest, CallbackRequest, SocialIdentity, SocialAuthTokenResult, ScopedIdentity};

final class SocialAuthProtocolTest extends ProtocolTestCase
{
    private SocialAuthRegistry $registry;
    private SocialAuthGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new SocialAuthRegistry($this->container, $this->availability);
        $this->gateway = new SocialAuthGateway($this->registry);
        $this->activate('plugin_a');
        $this->activate('plugin_b');
    }

    private function add(FakeSocialAuthAdapter $adapter, string $owner = 'plugin_a', bool $refresh = false, bool $pkce = false): SocialAuthReference
    {
        $registration = new SocialAuthRegistration();
        $registration->add(new SocialAuthDefinition('oauth', '网站登录', ['profile', 'user:email', 'https://provider.example/profile'], $refresh, $pkce),
            $adapter, [new SocialAuthProfile('default', 'issuer:app-original')]);
        $this->registry->commit($owner, $registration->channels());
        return new SocialAuthReference($owner, 'oauth', 'default', 'issuer:app-original');
    }

    public function testDiscoverySeparatesReadinessScopesAndOptionalRefresh(): void
    {
        $first = new FakeSocialAuthAdapter();
        $second = new RefreshableSocialAuthAdapter();
        $this->add($first);
        $this->add($second, 'plugin_b', true);
        self::assertCount(2, $this->registry->definitions());
        self::assertCount(2, $this->registry->discover(new SocialAuthRequirements(['user:email'])));
        self::assertCount(0, $this->registry->discover(new SocialAuthRequirements(['not_supported'])));
        $available = $this->registry->discover(new SocialAuthRequirements(requireRefresh: true));
        self::assertCount(1, $available);
        self::assertSame('plugin_b', $available[0]->reference->addonCode);
        $second->ready = new SocialAuthReadiness(false, null, 'not_configured');
        self::assertCount(1, $this->registry->discover(new SocialAuthRequirements()));
        $all = $this->registry->discover(new SocialAuthRequirements(availableOnly: false));
        self::assertCount(2, $all);
        self::assertSame('not_configured', $all[1]->readiness->reasonCode);
        self::assertSame(0, $first->exchanges + $second->exchanges);
    }

    public function testExplicitChannelReferenceNeverUsesFirstRegisteredProvider(): void
    {
        $first = new FakeSocialAuthAdapter();
        $second = new FakeSocialAuthAdapter();
        $this->add($first);
        $reference = $this->add($second, 'plugin_b');
        $identity = $this->gateway->authenticate($reference, new CallbackRequest(new Secret('code'), 'https://site.example/oauth/callback'));
        self::assertSame('user-123', $identity->subject);
        self::assertSame(0, $first->exchanges);
        self::assertSame(1, $second->exchanges);
        $this->assertProtocolError('reference_missing', fn () => $this->gateway->authenticate(
            new SocialAuthReference('plugin_b', 'missing', 'default', 'issuer:app-original'),
            new CallbackRequest(new Secret('code'), 'https://site.example/oauth/callback')));
    }

    public function testPkceRequiredInputsAreCheckedAndPassedWithoutLosingRedirectUrl(): void
    {
        $adapter = new FakeSocialAuthAdapter();
        $reference = $this->add($adapter, pkce: true);
        $url = 'https://site.example/oauth/callback';
        $this->assertProtocolError('input_invalid', fn () => $this->gateway->getAuthorizeUrl($reference, new AuthorizeRequest($url, 'state-original')));
        $verifier = str_repeat('a', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $result = $this->gateway->getAuthorizeUrl($reference, new AuthorizeRequest($url, 'state-original', ['user:email'], $challenge));
        parse_str(parse_url($result->url, PHP_URL_QUERY), $query);
        self::assertSame($url, $query['redirect_uri']);
        self::assertSame('state-original', $query['state']);
        self::assertSame($challenge, $query['code_challenge']);
        $this->assertProtocolError('input_invalid', fn () => $this->gateway->handleCallback($reference, new CallbackRequest(new Secret('code'), $url)));
        $this->gateway->authenticate($reference, new CallbackRequest(new Secret('code'), $url, new Secret($verifier)));
        self::assertSame($url, $adapter->callback->redirectUrl);
        self::assertSame($verifier, $adapter->callback->pkceVerifier->reveal());
    }

    public function testScopeTokensAllowProviderUrisButNotWhitespace(): void
    {
        $reference = $this->add(new FakeSocialAuthAdapter());
        $url = 'https://site.example/oauth/callback';
        self::assertNotEmpty($this->gateway->getAuthorizeUrl($reference,
            new AuthorizeRequest($url, 'state', ['https://provider.example/profile']))->url);
        $this->assertProtocolError('input_invalid', fn () => new AuthorizeRequest($url, 'state', ['profile email']));
        $this->assertProtocolError('input_invalid', fn () => $this->gateway->getAuthorizeUrl($reference, new AuthorizeRequest($url, 'state', ['admin'])));
    }

    public function testTokensAndVerifierCannotLeakThroughJsonDebugOrSerialization(): void
    {
        $reference = $this->add(new FakeSocialAuthAdapter());
        $tokens = $this->gateway->handleCallback($reference, new CallbackRequest(new Secret('code-secret'), 'https://site.example/oauth/callback'));
        $json = json_encode($tokens, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('oauth-access-secret', $json);
        self::assertStringNotContainsString('oauth-refresh-secret', $json);
        self::assertStringNotContainsString('oauth-access-secret', print_r($tokens, true));
        self::assertSame('oauth-access-secret', $tokens->accessToken->reveal());
        $this->assertProtocolError('secret_serialization_forbidden', fn () => serialize($tokens));
    }

    public function testIdentityNamespaceAndTokenSubjectAreCheckedBeforeBinding(): void
    {
        $adapter = new FakeSocialAuthAdapter();
        $reference = $this->add($adapter);
        $tokens = $this->gateway->handleCallback($reference, new CallbackRequest(new Secret('code'), 'https://site.example/oauth/callback'));
        $adapter->identity = new SocialIdentity('user-123', 'issuer:app-other');
        $this->assertProtocolError('identity_namespace_changed', fn () => $this->gateway->getUser($reference, $tokens));
        $adapter->identity = new SocialIdentity('user-other', 'issuer:app-original');
        $this->assertProtocolError('result_invalid', fn () => $this->gateway->getUser($reference, $tokens));
        $wrongTokens = new SocialAuthTokenResult(new Secret('access'), 'Bearer', 'another-issuer:app-original');
        $this->assertProtocolError('identity_namespace_changed', fn () => $this->gateway->getUser($reference, $wrongTokens));
    }

    public function testConfigurationDriftAndDisabledPluginCannotExchangeCode(): void
    {
        $adapter = new FakeSocialAuthAdapter();
        $reference = $this->add($adapter);
        $request = new CallbackRequest(new Secret('code'), 'https://site.example/oauth/callback');
        $adapter->ready = new SocialAuthReadiness(true, 'issuer:app-new');
        self::assertCount(0, $this->registry->discover(new SocialAuthRequirements()));
        $this->assertProtocolError('identity_namespace_changed', fn () => $this->gateway->handleCallback($reference, $request));
        $this->deactivate('plugin_a');
        $this->assertProtocolError('addon_unavailable', fn () => $this->gateway->handleCallback($reference, $request));
        self::assertSame(0, $adapter->exchanges);
    }

    public function testRefreshIsOptionalAndRegistrationRequiresActualInterface(): void
    {
        $first = $this->add(new FakeSocialAuthAdapter());
        $second = $this->add(new RefreshableSocialAuthAdapter(), 'plugin_b', true);
        $this->assertProtocolError('operation_unsupported', fn () => $this->gateway->refreshToken($first, new Secret('refresh')));
        self::assertSame('refreshed-access-secret', $this->gateway->refreshToken($second, new Secret('refresh'))->accessToken->reveal());
        $this->assertProtocolError('adapter_invalid', fn () => (new SocialAuthRegistration())->add(
            new SocialAuthDefinition('bad', '错误声明', supportsRefresh: true), new FakeSocialAuthAdapter(),
            [new SocialAuthProfile('default', 'issuer:app-original')]));
    }

    public function testIdentityPreservesVerifiedFlagsAndAdditionalIdentifierScopes(): void
    {
        $identity = new SocialIdentity('openid-fixture', 'wechat:app-one', email: 'person@example.com',
            additionalIdentities: [new ScopedIdentity('unionid', 'wechat:open-platform-one', 'unionid-fixture')]);
        self::assertFalse($identity->emailVerified);
        self::assertSame('wechat:open-platform-one', $identity->additionalIdentities[0]->identityNamespace);
        $this->assertProtocolError('result_invalid', fn () => new SocialIdentity('subject', 'issuer:app', emailVerified: true));
    }
}
