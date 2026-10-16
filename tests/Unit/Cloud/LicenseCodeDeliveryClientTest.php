<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Cloud;

use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;
use PTAdmin\Cloud\{CloudException, CloudProtocol, HttpCloudTransport, LicenseCodeDeliveryClient};
use PTAdmin\Core\ApplicationIdentity;

final class LicenseCodeDeliveryClientTest extends TestCase
{
    public function testSignedCodeDeliveryUsesIndependentEncryptedCredentialAndVerifiedDownload(): void
    {
        $previous = \Illuminate\Container\Container::getInstance();
        $directory = sys_get_temp_dir().'/code-client-'.bin2hex(random_bytes(6));
        $app = new Application($directory);
        $app->useStoragePath($directory);
        $identity = new ApplicationIdentity($directory.'/identity.json');
        $identity->createForInstall();
        $http = new Factory();
        $http->preventStrayRequests();
        $bytes = 'standalone package';
        $code = 'PT-'.implode('-', array_fill(0, 4, 'A1234567'));
        $access = str_repeat('b', 64);
        $http->fake(function ($request) use ($bytes, $code, $access) {
            if (str_ends_with($request->url(), '/authorize')) {
                self::assertSame([], $request->header('Authorization'));
                $values = $request->data();
                self::assertSame($code, $values['license_code']);
                self::assertArrayNotHasKey('private_key', $values);
                self::assertStringNotContainsString($code, CloudProtocol::codeDeliveryPayload($values));
                self::assertSame(1, openssl_verify(CloudProtocol::codeDeliveryPayload($values), base64_decode($values['signature']), $values['public_key'], OPENSSL_ALGO_SHA256));
                return Factory::response(['code' => 0, 'data' => ['credential_id' => 1, 'access_token' => $access, 'licenses' => []]]);
            }
            self::assertSame(['Bearer '.$access], $request->header('Authorization'));
            self::assertArrayNotHasKey('license_code', $request->data());
            if (str_ends_with($request->url(), '/ticket')) {
                return Factory::response(['code' => 0, 'data' => ['ticket' => str_repeat('c', 64), 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)]]);
            }
            self::assertSame(CloudProtocol::SITE_URL.'/api/cloud/code-delivery/download', $request->url());
            self::assertSame([str_repeat('c', 64)], $request->header('X-PTAdmin-Download-Ticket'));
            return Factory::response($bytes);
        });
        $client = new LicenseCodeDeliveryClient(new HttpCloudTransport($http), $identity, new Encrypter(str_repeat('x', 32), 'AES-256-CBC'));
        try {
            $options = $client->authorize($code);
            self::assertArrayNotHasKey('access_token', $options);
            $path = $directory.'/app/ptadmin/cloud/code-credentials/1.json';
            self::assertStringNotContainsString($access, file_get_contents($path));
            self::assertStringNotContainsString($code, file_get_contents($path));
            $result = $client->download(1, ['license_id' => 2, 'release_id' => 3], $directory.'/package.zip');
            self::assertSame($bytes, file_get_contents($result['artifact']));
            self::assertSame(0600, fileperms($result['artifact']) & 0777);
            $identity->rebuild();
            try {
                $client->request(1, 'preview', ['license_id' => 2, 'release_id' => 3]);
                self::fail('Copied credential must not survive an identity rebuild');
            } catch (CloudException $exception) {
                self::assertSame('code_credential_expired', $exception->errorCode);
            }
            $http->assertSentCount(3);
        } finally {
            (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($directory);
            \Illuminate\Container\Container::setInstance($previous);
        }
    }
}
