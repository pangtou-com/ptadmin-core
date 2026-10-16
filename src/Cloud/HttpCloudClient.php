<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

use PTAdmin\Cloud\Contracts\CloudClientInterface;
use PTAdmin\Core\ApplicationIdentity;
use PTAdmin\Cloud\Contracts\CloudTransportInterface;

/** Core 与官网云市场的固定协议入口。 */
final class HttpCloudClient implements CloudClientInterface, \PTAdmin\Cloud\Contracts\ProductDeliveryClientInterface
{
    public function __construct(private readonly CloudTransportInterface $transport, private readonly ApplicationIdentity $identity)
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
        $result = $this->transport->request('POST', '/requests', $values);
        if (!is_string($result['request_key'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $result['request_key']) !== 1) {
            throw new CloudException('平台授权请求响应无效。', 'cloud_response_invalid');
        }
        return CloudProtocol::SITE_URL.'/cloud/authorize/'.$result['request_key'];
    }

    public function exchangeAuthorizationCode(string $code, string $codeVerifier, string $redirectUri): array
    {
        return $this->transport->request('POST', '/token', [
            'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $codeVerifier,
            'redirect_uri' => $redirectUri, 'client_id' => $this->identity->load()['application_instance_id'],
        ]);
    }

    public function pollCliAuthorization(string $requestKey, string $codeVerifier): array
    {
        return $this->transport->request('POST', '/token', ['grant_type' => 'cli', 'request_key' => $requestKey,
            'code_verifier' => $codeVerifier, 'client_id' => $this->identity->load()['application_instance_id']]);
    }

    public function refresh(string $refreshToken): array
    {
        return $this->transport->request('POST', '/token', ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    public function products(string $accessToken, array $filters = []): array
    {
        return $this->transport->request('GET', '/products', $filters, $accessToken);
    }

    public function submitRelease(string $accessToken, string $type, array $payload): array
    {
        if (!in_array($type, ['addon', 'template'], true)) {
            throw new CloudException('未知商品类型。', 'invalid_request');
        }
        return $this->transport->request('POST', '/market/'.$type.'/releases/package', $payload, $accessToken);
    }

    public function session(string $accessToken): array
    {
        return $this->transport->request('GET', '/session', [], $accessToken);
    }

    public function logout(string $accessToken): void
    {
        $this->transport->request('POST', '/revoke', ['token' => $accessToken]);
    }

    public function catalog(array $filters = []): array
    {
        return $this->transport->request('GET', '/catalog', $filters);
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
        return $this->transport->request('POST', '/licenses/preview', $payload, $accessToken);
    }

    public function issueDownloadTicket(string $accessToken, array $payload): array
    {
        return $this->transport->request('POST', '/licenses/ticket', $payload, $accessToken);
    }

    public function migrateIp(string $accessToken, array $payload): array
    {
        throw $this->unavailable();
    }

    public function requestInstanceMigration(string $accessToken, array $payload): array
    {
        return $this->transport->request('POST', '/licenses/project', $payload, $accessToken);
    }

    public function deliveryOptions(string $accessToken, array $payload): array
    {
        return $this->transport->request('POST', '/licenses/options', $payload, $accessToken);
    }

    public function redeemLicenseCode(string $accessToken, array $payload): array
    {
        return $this->transport->request('POST', '/licenses/redeem', $payload, $accessToken);
    }

    public function prepareDelivery(string $accessToken, array $payload): array
    {
        return $this->transport->request('POST', '/licenses/prepare', $payload, $accessToken);
    }

    public function project(string $accessToken, array $payload): array
    {
        return $this->transport->request('POST', '/licenses/project', $payload, $accessToken);
    }

    public function bindLicense(string $accessToken, array $payload): array
    {
        return $this->transport->request('POST', '/licenses/bind', $payload, $accessToken);
    }

    public function releaseLicense(string $accessToken, array $payload): array
    {
        return $this->transport->request('POST', '/licenses/release', $payload, $accessToken);
    }

    public function downloadPackage(string $accessToken, array $payload, string $destination): array
    {
        $ticket = $this->issueDownloadTicket($accessToken, $payload);
        return (new VerifiedPackageDownloader($this->transport))->download($accessToken, $ticket, $destination);
    }

    private function unavailable(): CloudException
    {
        return new CloudException('云市场购买与订单功能尚未开放。', 'cloud_platform_unavailable');
    }
}
