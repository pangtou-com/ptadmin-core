<?php

declare(strict_types=1);

namespace PTAdmin\Cloud\Contracts;

/**
 * Stable boundary between the Core user client and the separately deployed cloud platform.
 * Implementations must never expose credentials or download tickets to controllers.
 */
interface CloudClientInterface
{
    /** @param array<string, mixed> $parameters */
    public function authorizationUrl(array $parameters): string;

    /** @return array<string, mixed> */
    public function exchangeAuthorizationCode(string $code, string $codeVerifier, string $redirectUri): array;

    /** @return array<string, mixed> */
    public function session(string $accessToken): array;

    public function logout(string $accessToken): void;

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function catalog(string $accessToken, array $filters = []): array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createOrder(string $accessToken, array $payload): array;

    /** @return array<string, mixed> */
    public function order(string $accessToken, string $orderId): array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function previewLicense(string $accessToken, array $payload): array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function issueDownloadTicket(string $accessToken, array $payload): array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function migrateIp(string $accessToken, array $payload): array;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function requestInstanceMigration(string $accessToken, array $payload): array;
}
