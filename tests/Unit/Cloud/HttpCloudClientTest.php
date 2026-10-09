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
        $this->client = new HttpCloudClient($this->http, $this->identity);
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
}
