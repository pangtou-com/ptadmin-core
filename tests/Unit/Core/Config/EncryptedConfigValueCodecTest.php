<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Config;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Config\ConfigValueCodec;

final class EncryptedConfigValueCodecTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Crypt::swap(new Encrypter(str_repeat('c', 32), 'AES-256-CBC'));
    }

    protected function tearDown(): void
    {
        Crypt::clearResolvedInstance('encrypter');
        parent::tearDown();
    }

    public function testPartialAndBlankUpdatesPreserveCiphertextAndReplacementEncryptsOnce(): void
    {
        $codec = new ConfigValueCodec();
        $schema = $this->schema();
        $stored = $codec->encodeOverrides($schema, ['token' => 'original']);
        self::assertSame('original', Crypt::decryptString($stored['token']));
        foreach ([[], ['token' => ''], ['token' => null], ['label' => 'changed']] as $update) {
            self::assertSame($stored['token'], $codec->encodeOverrides($schema, $update, $stored)['token']);
        }
        $replaced = $codec->encodeOverrides($schema, ['token' => 'replacement'], $stored);
        self::assertSame('replacement', Crypt::decryptString($replaced['token']));
        self::assertSame(['label' => 'default'], $codec->decode($schema, $stored));
        self::assertSame([], $codec->decode($schema, $stored, false));
    }

    public function testRequiredEncryptedCredentialCannotBeBlankOnFirstSave(): void
    {
        $schema = $this->schema();
        $schema['fields'][0]['required'] = true;
        $this->expectException(InvalidArgumentException::class);
        (new ConfigValueCodec())->encodeOverrides($schema, ['token' => '']);
    }

    public function testHiddenDefaultsAndUnknownStoredKeysCannotBypassOutputFiltering(): void
    {
        $schema = $this->schema();
        $schema['fields'][0]['extensions']['secret']['storage_mode'] = 'plain';
        $schema['fields'][0]['default'] = 'legacy-default-secret';
        $codec = new ConfigValueCodec();
        self::assertSame(['label' => 'default'], $codec->decode($schema, ['removed_secret' => 'old-value']));
        self::assertSame([], $codec->decode($schema, ['token' => 'secret'], false));
    }

    public function testReadableEncryptedFieldsUseTheSameMaskForEffectiveValuesAndOverrides(): void
    {
        $schema = $this->schema();
        $schema['fields'][0]['extensions'] = [
            'secret' => ['storage_mode' => 'encrypted', 'readable' => true],
            'mask' => ['type' => 'email'],
        ];
        $codec = new ConfigValueCodec();
        $stored = $codec->encodeOverrides($schema, ['token' => 'alice@example.com']);
        self::assertSame('a****@example.com', $codec->decode($schema, $stored)['token']);
        self::assertSame(['token' => 'a****@example.com'], $codec->decode($schema, $stored, false));
    }

    private function schema(): array
    {
        return ['name' => 'credentials', 'title' => '平台凭据', 'fields' => [
            ['name' => 'token', 'type' => 'string', 'extensions' => ['secret' => ['storage_mode' => 'encrypted', 'readable' => false]]],
            ['name' => 'label', 'type' => 'string', 'default' => 'default'],
        ]];
    }
}
