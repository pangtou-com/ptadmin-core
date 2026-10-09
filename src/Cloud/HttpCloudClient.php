<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

use PTAdmin\Cloud\Contracts\CloudClientInterface;
use PTAdmin\Core\ApplicationIdentity;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\ConnectionException;

/** Core 与官网云市场的固定协议入口。 */
final class HttpCloudClient implements CloudClientInterface
{
    public function __construct(private readonly Factory $http, private readonly ApplicationIdentity $identity)
    {
    }

    public function authorizationUrl(array $parameters): string
    {
        $identity = $this->identity->load();
        $values = array_merge($parameters, [
            'instance_id' => $identity['application_instance_id'], 'public_key' => $identity['public_key'],
            'timestamp' => time(), 'nonce' => bin2hex(random_bytes(32)),
        ]);
        openssl_sign(CloudProtocol::authorizationPayload($values), $signature, $identity['private_key'], OPENSSL_ALGO_SHA256);
        $values['signature'] = base64_encode($signature);
        $result = $this->request('POST', '/requests', $values);
        if (!is_string($result['request_key'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $result['request_key']) !== 1) {
            throw new CloudException('平台授权请求响应无效。', 'cloud_response_invalid');
        }
        return CloudProtocol::SITE_URL.'/cloud/authorize/'.$result['request_key'];
    }

    public function exchangeAuthorizationCode(string $code, string $codeVerifier, string $redirectUri): array
    {
        return $this->request('POST', '/token', [
            'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $codeVerifier,
            'redirect_uri' => $redirectUri, 'client_id' => $this->identity->load()['application_instance_id'],
        ]);
    }

    public function pollCliAuthorization(string $requestKey, string $codeVerifier): array
    {
        return $this->request('POST', '/token', ['grant_type' => 'cli', 'request_key' => $requestKey,
            'code_verifier' => $codeVerifier, 'client_id' => $this->identity->load()['application_instance_id']]);
    }

    public function refresh(string $refreshToken): array
    {
        return $this->request('POST', '/token', ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    public function products(string $accessToken, array $filters = []): array
    {
        return $this->request('GET', '/products', $filters, $accessToken);
    }

    public function submitRelease(string $accessToken, string $type, array $payload): array
    {
        if (!in_array($type, ['addon', 'template'], true)) {
            throw new CloudException('未知商品类型。', 'invalid_request');
        }
        return $this->request('POST', '/market/'.$type.'/releases/package', $payload, $accessToken);
    }

    public function session(string $accessToken): array
    {
        return $this->request('GET', '/session', [], $accessToken);
    }

    public function logout(string $accessToken): void
    {
        $this->request('POST', '/revoke', ['token' => $accessToken]);
    }

    public function catalog(string $accessToken, array $filters = []): array
    {
        throw $this->unavailable();
    }

    public function createOrder(string $accessToken, array $payload): array
    {
        throw $this->unavailable();
    }

    public function order(string $accessToken, string $orderId): array
    {
        throw $this->unavailable();
    }

    public function previewLicense(string $accessToken, array $payload): array
    {
        throw $this->unavailable();
    }

    public function issueDownloadTicket(string $accessToken, array $payload): array
    {
        throw $this->unavailable();
    }

    public function migrateIp(string $accessToken, array $payload): array
    {
        throw $this->unavailable();
    }

    public function requestInstanceMigration(string $accessToken, array $payload): array
    {
        throw $this->unavailable();
    }

    private function request(string $method, string $path, array $payload = [], ?string $token = null): array
    {
        try {
            $request = $this->http->acceptJson()->asJson()->connectTimeout(5)->timeout(60)->withoutRedirecting();
            if ($token !== null) {
                $request = $request->withToken($token);
            }
            $response = $request->send($method, CloudProtocol::SITE_URL.'/api/cloud'.$path, [$method === 'GET' ? 'query' : 'json' => $payload]);
        } catch (ConnectionException $exception) {
            throw new CloudException('无法连接云市场平台，请稍后重试。', 'cloud_platform_unavailable');
        }
        $result = $response->json();
        if (!$response->successful() || !is_array($result) || ($result['code'] ?? null) !== 0 || !is_array($result['data'] ?? null)) {
            throw new CloudException(is_string($result['message'] ?? null) ? $result['message'] : '平台响应无效。',
                $response->status() === 401 ? 'unauthorized' : ($response->status() === 403 ? 'forbidden' : ($response->status() === 422 ? 'invalid_request' : 'cloud_request_failed')));
        }
        return $result['data'];
    }

    private function unavailable(): CloudException
    {
        return new CloudException('云市场商品浏览、购买及授权安装功能尚未开放。', 'cloud_platform_unavailable');
    }
}
