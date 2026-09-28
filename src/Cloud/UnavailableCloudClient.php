<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

use PTAdmin\Cloud\Contracts\CloudClientInterface;

/** Default binding until the separately deployed platform publishes its protocol. */
final class UnavailableCloudClient implements CloudClientInterface
{
    public function authorizationUrl(array $parameters): string
    {
        throw $this->unavailable();
    }

    public function exchangeAuthorizationCode(string $code, string $codeVerifier, string $redirectUri): array
    {
        throw $this->unavailable();
    }

    public function session(string $accessToken): array
    {
        throw $this->unavailable();
    }

    public function logout(string $accessToken): void
    {
        throw $this->unavailable();
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

    private function unavailable(): CloudException
    {
        return new CloudException('云市场平台接口尚未配置。', 'cloud_platform_unavailable');
    }
}
