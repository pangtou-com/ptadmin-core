<?php

declare(strict_types=1);

namespace PTAdmin\SocialAuth;

use PTAdmin\Core\ServiceProtocol\{ProtocolException, Secret};
use PTAdmin\SocialAuth\Contracts\RefreshableSocialAuthInterface;

final class SocialAuthGateway
{
    public function __construct(private SocialAuthRegistry $registry)
    {
    }

    public function getAuthorizeUrl(SocialAuthReference $reference, AuthorizeRequest $request): AuthorizeResult
    {
        $definition = $this->registry->channel($reference)->definition;
        if (array_diff($request->scopes, $definition->scopes) !== []) {
            throw new ProtocolException('input_invalid', '登录授权范围不在渠道声明中。');
        }
        if ($definition->requiresPkce && $request->pkceChallenge === null) {
            throw new ProtocolException('input_invalid', '登录渠道要求 PKCE S256 challenge。');
        }
        return $this->registry->adapter($reference)->getAuthorizeUrl($reference, $request);
    }

    public function handleCallback(SocialAuthReference $reference, CallbackRequest $request): SocialAuthTokenResult
    {
        if ($this->registry->channel($reference)->definition->requiresPkce && $request->pkceVerifier === null) {
            throw new ProtocolException('input_invalid', '登录渠道回调要求原 PKCE verifier。');
        }
        $tokens = $this->registry->adapter($reference)->handleCallback($reference, $request);
        $this->assertNamespace($reference, $tokens->identityNamespace);
        return $tokens;
    }

    public function getUser(SocialAuthReference $reference, SocialAuthTokenResult $tokens): SocialIdentity
    {
        $this->assertNamespace($reference, $tokens->identityNamespace);
        $identity = $this->registry->adapter($reference)->getUser($reference, $tokens);
        $this->assertNamespace($reference, $identity->identityNamespace);
        if ($tokens->subject !== null && $tokens->subject !== $identity->subject) {
            throw new ProtocolException('result_invalid', '用户资料身份与令牌交换身份不一致。');
        }
        return $identity;
    }

    /** 宿主完成 state 核对后使用，服务端令牌不会交给页面。 */
    public function authenticate(SocialAuthReference $reference, CallbackRequest $request): SocialIdentity
    {
        return $this->getUser($reference, $this->handleCallback($reference, $request));
    }

    public function refreshToken(SocialAuthReference $reference, Secret $refreshToken): SocialAuthTokenResult
    {
        if (!$this->registry->channel($reference)->definition->supportsRefresh) {
            throw new ProtocolException('operation_unsupported', '登录渠道未声明令牌刷新能力。');
        }
        $adapter = $this->registry->adapter($reference);
        if (!$adapter instanceof RefreshableSocialAuthInterface) {
            throw new ProtocolException('adapter_invalid', '登录适配器未实现刷新契约。');
        }
        $tokens = $adapter->refreshToken($reference, $refreshToken);
        $this->assertNamespace($reference, $tokens->identityNamespace);
        return $tokens;
    }

    private function assertNamespace(SocialAuthReference $reference, string $namespace): void
    {
        if ($namespace !== $reference->identityNamespace) {
            throw new ProtocolException('identity_namespace_changed', '第三方身份或令牌不属于选定渠道的应用身份域。');
        }
    }
}
