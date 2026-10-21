<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Cloud;

use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;
use PTAdmin\Cloud\CloudException;
use PTAdmin\Cloud\CloudProtocol;
use PTAdmin\Cloud\HttpCloudClient;
use PTAdmin\Core\ApplicationIdentity;

final class HttpCloudClientTest extends TestCase
{
    private string $path;
    private ApplicationIdentity $identity;
    private Factory $http;
    private HttpCloudClient $client;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/cloud-client-'.bin2hex(random_bytes(6)).'.json';
        $this->identity = new ApplicationIdentity($this->path);
        $this->identity->createForInstall();
        $this->http = new Factory();
        $this->http->preventStrayRequests();
        $this->client = new HttpCloudClient(new \PTAdmin\Cloud\HttpCloudTransport($this->http), $this->identity);
    }

    protected function tearDown(): void
    {
        unlink($this->path);
    }

    public function testAuthorizationRequestUsesFixedSiteAndSignedPublicIdentity(): void
    {
        $this->http->fake(function ($request) {
            self::assertSame('http://127.0.0.1:8001/api/cloud/requests', $request->url());
            $values = $request->data();
            self::assertArrayNotHasKey('private_key', $values);
            self::assertSame(1, openssl_verify(CloudProtocol::authorizationPayload($values), base64_decode($values['signature'], true), $values['public_key'], OPENSSL_ALGO_SHA256));
            return Factory::response(['code' => 0, 'data' => ['request_key' => str_repeat('a', 64)]]);
        });
        self::assertSame('http://127.0.0.1:8001/cloud/authorize/'.str_repeat('a', 64), $this->client->authorizationUrl([
            'redirect_uri' => 'https://core.example/ptadmin/cloud/session/callback', 'state' => str_repeat('b', 64),
            'code_challenge' => CloudProtocol::challenge(str_repeat('v', 64)), 'code_challenge_method' => 'S256',
        ]));
    }

    public function testCliRequestBindsFlowAndPollKeepsVerifierOutOfBrowserUrl(): void
    {
        $verifier = str_repeat('v', 64);
        $this->http->fake(function ($request) use ($verifier) {
            if (str_ends_with($request->url(), '/requests')) {
                $values = $request->data();
                self::assertSame('cli', $values['flow']);
                self::assertSame('', $values['redirect_uri']);
                self::assertArrayNotHasKey('code_verifier', $values);
                self::assertSame(1, openssl_verify(CloudProtocol::authorizationPayload($values), base64_decode($values['signature'], true), $values['public_key'], OPENSSL_ALGO_SHA256));
                $values['flow'] = 'browser';
                self::assertSame(0, openssl_verify(CloudProtocol::authorizationPayload($values), base64_decode($values['signature'], true), $values['public_key'], OPENSSL_ALGO_SHA256));
                return Factory::response(['code' => 0, 'data' => ['request_key' => str_repeat('a', 64)]]);
            }
            self::assertSame('cli', $request['grant_type']);
            self::assertSame($verifier, $request['code_verifier']);
            self::assertSame($this->identity->load()['application_instance_id'], $request['client_id']);
            return Factory::response(['code' => 0, 'data' => ['status' => 'pending']]);
        });
        $url = $this->client->authorizationUrl(['flow' => 'cli', 'redirect_uri' => '', 'state' => str_repeat('b', 64),
            'code_challenge' => CloudProtocol::challenge($verifier), 'code_challenge_method' => 'S256']);
        self::assertStringNotContainsString($verifier, $url);
        self::assertSame(['status' => 'pending'], $this->client->pollCliAuthorization(basename($url), $verifier));
    }

    public function testUnauthenticatedAndMalformedRemoteResponsesFailClosed(): void
    {
        $this->http->fakeSequence()->push(['message' => '请重新授权'], 401)->push('<html>upstream error</html>', 200)->push(['message' => '包格式无效'], 422);
        foreach (['unauthorized', 'cloud_request_failed', 'invalid_request'] as $expected) {
            try {
                $this->client->session('test-token');
                self::fail('Invalid response must be rejected');
            } catch (CloudException $exception) {
                self::assertSame($expected, $exception->errorCode);
            }
        }
    }

    public function testProductPaginationAndUploadUseServerBearerOnly(): void
    {
        $this->http->fake(function ($request) {
            self::assertSame(['Bearer server-token'], $request->header('Authorization'));
            if ($request->method() === 'GET') {
                self::assertSame('2', (string) $request['page']);
                return Factory::response(['code' => 0, 'data' => ['results' => [], 'total' => 51]]);
            }
            self::assertSame('http://127.0.0.1:8001/api/cloud/market/template/releases/package', $request->url());
            return Factory::response(['code' => 0, 'data' => ['id' => 7, 'validation_state' => 'validated']]);
        });
        self::assertSame(51, $this->client->products('server-token', ['page' => 2])['total']);
        self::assertSame(7, $this->client->submitRelease('server-token', 'template', ['product_id' => 1])['id']);
    }
    public function testCatalogUsesPublicEndpointWithoutBearerAndPreservesServerPagination(): void
    {
        $this->http->fake(function ($request) {
            self::assertStringStartsWith(CloudProtocol::SITE_URL.'/api/cloud/catalog?', $request->url());
            self::assertSame([], $request->header('Authorization'));
            self::assertSame('template', $request['product_type']);
            self::assertSame('2', (string) $request['page']);
            self::assertSame('content', $request['category']);
            self::assertSame('主题', $request['keyword']);
            self::assertSame(['theme', 'classic'], $request['codes']);
            return Factory::response(['code' => 0, 'data' => ['results' => [['code' => 'theme']], 'total' => 13, 'page' => 2]]);
        });
        $catalog = $this->client->catalog(['product_type' => 'template', 'page' => 2, 'category' => 'content', 'keyword' => '主题', 'codes' => ['theme', 'classic']]);
        self::assertSame(13, $catalog['total']);
        self::assertSame('theme', $catalog['results'][0]['code']);
    }

    public function testInvalidTicketIsRejectedBeforeAnyDownload(): void
    {
        $bytes = 'verified package';
        $this->http->fake(function ($request) use ($bytes) {
            self::assertSame(['Bearer private-access'], $request->header('Authorization'));
            if (str_ends_with($request->url(), '/ticket')) {
                return Factory::response(['code' => 0, 'data' => ['ticket' => str_repeat('t', 64), 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)]]);
            }
            return Factory::response($bytes);
        });
        // 非十六进制票据在下载前被拒绝，不创建 artifact。
        $destination = $this->path.'.zip';
        try {
            $this->client->downloadPackage('private-access', ['license_id' => 1, 'release_id' => 2], $destination);
            self::fail('Invalid ticket accepted');
        } catch (CloudException $exception) {
            self::assertSame('cloud_response_invalid', $exception->errorCode);
        }
        self::assertFileDoesNotExist($destination);
        $this->http->assertSentCount(1);
    }

    public function testLicenseCodeRedemptionUsesAuthenticatedPostAndPreservesRateLimitFailure(): void
    {
        $code = 'PT-'.implode('-', array_fill(0, 4, 'A1234567'));
        $this->http->fake(function ($request) use ($code) {
            self::assertSame(CloudProtocol::SITE_URL.'/api/cloud/licenses/redeem', $request->url());
            self::assertSame('POST', $request->method());
            self::assertSame(['Bearer server-token'], $request->header('Authorization'));
            self::assertSame($code, $request['license_code']);
            self::assertSame(12, $request['expected_user_id']);
            return Factory::response(['message' => '授权码尝试过于频繁，请一分钟后重试。'], 429);
        });
        try {
            $this->client->redeemLicenseCode('server-token', ['license_code' => $code, 'expected_user_id' => 12]);
            self::fail('Rate limit was ignored');
        } catch (CloudException $exception) {
            self::assertSame('too_many_requests', $exception->errorCode);
            self::assertStringNotContainsString($code, $exception->getMessage());
        }
    }

    public function testChecksumFailureKeepsExistingArtifactAndCleansPartialDownload(): void
    {
        $this->http->fakeSequence()
            ->push(['code' => 0, 'data' => ['ticket' => str_repeat('a', 64), 'sha256' => hash('sha256', 'expected'), 'size' => 8]])
            ->push('tampered');
        $destination = $this->path.'.zip';
        file_put_contents($destination, 'existing');
        try {
            $this->client->downloadPackage('private-access', ['license_id' => 1, 'release_id' => 2], $destination);
            self::fail('Tampered package accepted');
        } catch (CloudException $exception) {
            self::assertSame('checksum_mismatch', $exception->errorCode);
            self::assertStringNotContainsString('private-access', $exception->getMessage());
        } finally {
            self::assertSame('existing', file_get_contents($destination));
            self::assertSame([], glob($destination.'.*.tmp'));
            unlink($destination);
        }
    }

    public function testDownloadUsesFixedEndpointAndPrivateHeaderAndPublishesVerifiedFile(): void
    {
        $bytes = 'verified package';
        $this->http->fake(function ($request) use ($bytes) {
            self::assertSame(['Bearer private-access'], $request->header('Authorization'));
            if (str_ends_with($request->url(), '/ticket')) {
                return Factory::response(['code' => 0, 'data' => ['ticket' => str_repeat('a', 64), 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes), 'url' => 'https://untrusted.invalid/package']]);
            }
            self::assertSame(CloudProtocol::SITE_URL.'/api/cloud/licenses/download', $request->url());
            self::assertSame([str_repeat('a', 64)], $request->header('X-PTAdmin-Download-Ticket'));
            return Factory::response($bytes);
        });
        $destination = $this->path.'.zip';
        try {
            $result = $this->client->downloadPackage('private-access', ['license_id' => 1, 'release_id' => 2], $destination);
            self::assertSame(hash('sha256', $bytes), $result['sha256']);
            self::assertSame($bytes, file_get_contents($destination));
            self::assertArrayNotHasKey('ticket', $result);
        } finally {
            if (is_file($destination)) {
                unlink($destination);
            }
        }
    }

}
