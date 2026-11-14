<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Install;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PTAdmin\Core\Install\EnvironmentFile;
use Symfony\Component\Process\Process;

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

    public static function specialValues(): iterable
    {
        yield 'backslash' => ['abc\\123xyz'];
        yield 'variable syntax' => ['abc${APP_NAME}xyz'];
        yield 'replacement syntax' => ['abc$1${1}xyz'];
        yield 'quotes and spaces' => [' p#ss$word"quote\\slash '];
        yield 'empty' => [''];
    }

    #[DataProvider('specialValues')]
    public function testAddingAndReplacingValuesPreservesLiteralCharacters(string $value): void
    {
        $path = $this->directory.'/.env';
        $header = "APP_NAME=Example\n# Keep host settings\n";
        file_put_contents($path, $header);
        chmod($path, 0640);
        $environment = new EnvironmentFile($path);

        $environment->put('DB_PASSWORD', $value);
        $added = file_get_contents($path);
        self::assertSame($value, $environment->get('DB_PASSWORD'));

        $environment->put('DB_PASSWORD', 'old-password');
        $environment->put('DB_PASSWORD', $value);
        self::assertSame($added, file_get_contents($path));
        self::assertSame($value, $environment->get('DB_PASSWORD'));
        self::assertSame('Example', $environment->get('APP_NAME'));
        self::assertSame(0640, fileperms($path) & 0777);
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

    public function testLaravelReloadPreservesStringValues(): void
    {
        $path = $this->directory.'/.env';
        $environment = new EnvironmentFile($path);
        $values = ['null', 'NULL', 'false', 'FaLsE', 'true', 'empty', '(null)', '(false)', '(TRUE)', '(empty)', '"null"', "'password'", '""', "''"];
        foreach (self::specialValues() as [$value]) {
            $values[] = $value;
        }
        $expected = [];
        foreach ($values as $index => $value) {
            $key = 'INSTALL_VALUE_'.$index;
            $environment->put($key, 'old');
            $environment->put($key, $value);
            self::assertSame($value, $environment->get($key));
            $expected[$key] = $value;
        }

        $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/Fixtures/Install/reload-environment.php', $path]);
        $process->mustRun();
        self::assertSame($expected, json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR)['values']);
    }

    public static function existingAssignments(): iterable
    {
        yield 'duplicates' => ["DB_PREFIX=first_\nexport DB_PREFIX=last_\n", 'DB_PREFIX'];
        yield 'multiline' => ["APP_NAME=\"Old\nName\"\n", 'APP_NAME'];
        yield 'CRLF and escaped quotes' => ["APP_NAME=\"Old \\\"quoted\\\"\r\nName\"\r\n", 'APP_NAME'];
        yield 'indented quoted name' => ["  export \"APP_NAME\" = 'Old'\n", 'APP_NAME'];
        yield 'name inside another value' => ["APP_NAME=\"Old\nDB_PREFIX=embedded_\"\nDB_PREFIX=old_\n", 'DB_PREFIX'];
    }

    #[DataProvider('existingAssignments')]
    public function testUpdatingCompleteAssignmentsPreservesOtherConfiguration(string $assignment, string $key): void
    {
        $path = $this->directory.'/.env';
        $header = "# Keep comment\r\nHOST_SECRET=\"line one\r\nAPP_NAME=inside\r\nline three\"\r\n";
        $footer = "# Keep footer\nAPP_ENV=production\n";
        file_put_contents($path, $header.$assignment.$footer);
        $before = \Dotenv\Dotenv::parse((string) file_get_contents($path));

        $environment = new EnvironmentFile($path);
        $environment->put($key, 'new_value');
        $updated = (string) file_get_contents($path);
        self::assertSame(array_replace($before, [$key => 'new_value']), \Dotenv\Dotenv::parse($updated));
        self::assertStringStartsWith($header, $updated);
        self::assertStringEndsWith($footer, $updated);
        $environment->put($key, 'new_value');
        self::assertSame($updated, file_get_contents($path));
    }

    public function testInvalidExistingSyntaxIsNotOverwritten(): void
    {
        $path = $this->directory.'/.env';
        $contents = 'DB_PASSWORD="secret without closing quote';
        file_put_contents($path, $contents);
        try {
            (new EnvironmentFile($path))->put('APP_NAME', 'Example');
            self::fail('Invalid environment syntax must be rejected before writing.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The PTAdmin environment file syntax is invalid.', $exception->getMessage());
        }
        self::assertSame($contents, file_get_contents($path));
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
