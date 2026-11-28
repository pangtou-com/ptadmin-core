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

    public function testPhpProbeDoesNotRequireComposer(): void
    {
        (new UpdateRuntimeProbe())->assertUsable(PHP_BINARY);
        self::assertSame([], (new UpdateRuntimeProbe())->runtimeIssues(PHP_VERSION, get_loaded_extensions(), PHP_VERSION, get_loaded_extensions()));
    }

    public function testUnavailablePhpReportsTerminalRemediation(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('PHP CLI');
        (new UpdateRuntimeProbe())->assertUsable('/missing-ptadmin-php');
    }
}
