<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Schema;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\TestCase;
use PTAdmin\Schema\Definition\Schema;
use PTAdmin\Schema\Support\FieldProtection;

final class FieldProtectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Crypt::swap(new Encrypter(str_repeat('s', 32), 'AES-256-CBC'));
    }

    protected function tearDown(): void
    {
        Crypt::clearResolvedInstance('encrypter');
        parent::tearDown();
    }

    public function testEncryptedValuesRoundTripWithoutReturningHiddenSecrets(): void
    {
        $field = $this->field();
        $first = FieldProtection::store('private-token', $field);
        $second = FieldProtection::store('private-token', $field);
        self::assertNotSame($first, $second);
        self::assertStringNotContainsString('private-token', $first);
        self::assertSame('private-token', FieldProtection::reveal($first, $field));
        self::assertTrue(FieldProtection::hidden($field));
        self::assertNull(FieldProtection::output($first, $field));
        self::assertNull(FieldProtection::reveal(null, $field));
    }

    public function testReadableValuesAreDecryptedBeforeMasking(): void
    {
        $field = $this->field();
        $field['extensions']['secret']['readable'] = true;
        $encrypted = FieldProtection::store('alice@example.com', $field);
        self::assertSame('alice@example.com', FieldProtection::output($encrypted, $field));
        $field['extensions']['mask'] = ['type' => 'email'];
        self::assertSame('a****@example.com', FieldProtection::output($encrypted, $field));
    }

    public function testTamperedCiphertextFailsWithoutEchoingTheValue(): void
    {
        $this->expectException(DecryptException::class);
        $this->expectExceptionMessage('敏感字段解密失败：token');
        FieldProtection::reveal('not-a-ciphertext', $this->field());
    }

    public function testWrongApplicationKeyCannotDecryptStoredCredentials(): void
    {
        $encrypted = FieldProtection::store('private-token', $this->field());
        Crypt::swap(new Encrypter(str_repeat('x', 32), 'AES-256-CBC'));
        $this->expectException(DecryptException::class);
        FieldProtection::reveal($encrypted, $this->field());
    }

    public function testSchemaRejectsInvalidEncryptedFieldDeclarations(): void
    {
        self::assertSame([], (new Schema(['name' => 'secrets', 'title' => '凭据', 'fields' => [$this->field()]]))->validate());
        foreach ([
            ['extensions' => ['secret' => ['storage_mode' => 'unknown']]],
            ['multiple' => true], ['searchable' => true], ['sortable' => true],
            ['default' => 'private-token'], ['type' => 'json'],
        ] as $changes) {
            $schema = new Schema(['name' => 'secrets', 'title' => '凭据', 'fields' => [array_replace($this->field(), $changes)]]);
            self::assertNotEmpty($schema->validate(), json_encode($changes));
        }
        foreach (['indexes', 'uniques'] as $constraint) {
            self::assertNotEmpty((new Schema([
                'name' => 'secrets', 'title' => '凭据', 'fields' => [$this->field()],
                'constraints' => [$constraint => [['fields' => ['token']]]],
            ]))->validate());
        }
    }

    private function field(): array
    {
        return ['name' => 'token', 'label' => '令牌', 'type' => 'string', 'extensions' => ['secret' => ['storage_mode' => 'encrypted', 'readable' => false]]];
    }
}
