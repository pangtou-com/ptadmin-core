<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Install;

use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Install\EnvironmentFile;

final class EnvironmentFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/ptadmin-env-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
        parent::tearDown();
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }
            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        @rmdir($directory);
    }

    public function testItAddsTheWebPrefixWithoutChangingExistingValues(): void
    {
        $path = $this->directory.'/.env';
        file_put_contents($path, "APP_NAME=Example\n# Existing comment\n");

        (new EnvironmentFile($path))->put('PTADMIN_WEB_PREFIX', 'admin_a1b2c3');

        self::assertSame(
            "APP_NAME=Example\n# Existing comment\nPTADMIN_WEB_PREFIX=admin_a1b2c3\n",
            file_get_contents($path)
        );
    }

    public function testItReplacesOnlyTheRequestedValue(): void
    {
        $path = $this->directory.'/.env';
        file_put_contents($path, "PTADMIN_WEB_PREFIX=admin_old\nAPP_ENV=local\n");

        (new EnvironmentFile($path))->put('PTADMIN_WEB_PREFIX', 'admin_new123');

        self::assertSame("PTADMIN_WEB_PREFIX=admin_new123\nAPP_ENV=local\n", file_get_contents($path));
    }

    public function testItWritesSpecialCharactersThatLaravelDotenvCanRead(): void
    {
        $path = $this->directory.'/.env';
        $value = 'p#ss$word"quote\\slash';
        file_put_contents($path, "APP_ENV=local\n");

        (new EnvironmentFile($path))->put('DB_PASSWORD', $value);

        self::assertSame($value, \Dotenv\Dotenv::parse((string) file_get_contents($path))['DB_PASSWORD']);
    }

    public function testItReadsAnExistingQuotedKeyWithoutWritingTheEnvironment(): void
    {
        $path = $this->directory.'/.env';
        $contents = "# Preserve configuration\nexport APP_KEY=\"base64:existing+key/=\"\n";
        file_put_contents($path, $contents);

        $environment = new EnvironmentFile($path);
        self::assertSame('base64:existing+key/=', $environment->get('APP_KEY'));
        self::assertNull($environment->get('MISSING_KEY'));
        self::assertSame($contents, file_get_contents($path));
    }

    public function testItCreatesAMissingEnvironmentWithPrivatePermissions(): void
    {
        $path = $this->directory.'/.env';
        $environment = new EnvironmentFile($path);
        self::assertNull($environment->get('APP_KEY'));
        self::assertFileDoesNotExist($path);

        $environment->put('APP_KEY', 'base64:new+key/=');
        self::assertSame('base64:new+key/=', $environment->get('APP_KEY'));
        self::assertSame(0600, fileperms($path) & 0777);
    }

    public function testInvalidEnvironmentSyntaxDoesNotExposeItsContents(): void
    {
        $path = $this->directory.'/.env';
        file_put_contents($path, 'APP_KEY=unquoted secret value');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The PTAdmin environment file syntax is invalid.');
        (new EnvironmentFile($path))->get('APP_KEY');
    }
}
