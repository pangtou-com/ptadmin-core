<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Integration;

use Orchestra\Testbench\TestCase;
use PTAdmin\Core\Updates\{UpdateException, UpdateRuntimeProbe};

final class UpdateRuntimeProbeTest extends TestCase
{
    public function testSapiMarkersAndDebugExtensionsDoNotBlockMatchingBusinessRuntime(): void
    {
        $probe = new UpdateRuntimeProbe();
        self::assertSame([], $probe->runtimeIssues('8.2.28', ['PDO', 'json', 'cgi-fcgi', 'Zend OPcache'], '8.2.28', ['pdo', 'json', 'xdebug']));
        self::assertSame([], $probe->runtimeIssues('8.2.28', ['PDO', 'apache2handler'], '8.2.28', ['PDO']));
        $issues = $probe->runtimeIssues('8.2.28', ['pdo', 'pdo_mysql'], '8.3.0', ['pdo', 'redis']);
        self::assertCount(3, $issues);
        self::assertStringContainsString('8.3.0', $issues[0]);
        self::assertStringContainsString('CLI 缺少扩展：pdo_mysql', $issues[1]);
        self::assertStringContainsString('网页 PHP 缺少扩展：redis', $issues[2]);
    }

    public function testDetectedComposerMustActuallyExecuteAndDoesNotExposeRawOutput(): void
    {
        $composer = tempnam(sys_get_temp_dir(), 'ptadmin-composer-probe-');
        try {
            file_put_contents($composer, '<?php fwrite(STDERR, "secret-token"); exit(7);');
            try {
                (new UpdateRuntimeProbe())->assertUsable(PHP_BINARY, $composer);
                self::fail('不可用的 Composer 不应通过执行探测');
            } catch (UpdateException $error) {
                self::assertSame('runtime_unavailable', $error->reason);
                self::assertStringContainsString('Composer 执行失败（退出码 7）', $error->getMessage());
                self::assertStringNotContainsString('secret-token', $error->getMessage());
            }
            file_put_contents($composer, '<?php exit(in_array("--version", $argv, true) && in_array("--no-plugins", $argv, true) && in_array("--no-scripts", $argv, true) ? 0 : 8);');
            (new UpdateRuntimeProbe())->assertUsable(PHP_BINARY, $composer);
            self::assertFileExists($composer);
        } finally {
            unlink($composer);
        }
    }

    public function testUnavailablePhpReportsTerminalRemediation(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('PHP CLI');
        (new UpdateRuntimeProbe())->assertUsable('/missing-ptadmin-php', '/missing-composer');
    }
}
