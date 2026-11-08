<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Install;

use PHPUnit\Framework\TestCase;
use PTAdmin\Core\ApplicationIdentity;
use PTAdmin\Core\Install\InstallLock;
use PTAdmin\Core\Install\InstallStateMachine;
use PTAdmin\Core\Install\InstallStateStore;
use PTAdmin\Core\Install\Exceptions\InvalidInstallStateException;
use PTAdmin\Core\Install\WebPrefixGenerator;

final class InstallStateMachineTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/ptadmin-install-'.bin2hex(random_bytes(6));
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

    public function testPrepareIsIdempotentAndKeepsTheGeneratedPrefix(): void
    {
        $machine = $this->machine();
        $first = $machine->prepare();
        $second = $machine->prepare();

        self::assertMatchesRegularExpression('/^[a-z][a-z0-9]{11}$/', $first['state']['web_prefix']);
        self::assertSame($first['state']['web_prefix'], $second['state']['web_prefix']);
        self::assertSame($first['state']['core_version'], '0.1.0');
        self::assertFalse($second['installed']);
    }

    public function testPrepareAcceptsAValidatedCustomPrefix(): void
    {
        $state = $this->machine()->prepare('manage');

        self::assertSame('manage', $state['state']['web_prefix']);
    }

    public function testPreparePreservesLegacyPrefixOnRetryAndAfterInstallation(): void
    {
        $prefix = 'admin_6c24fd45aea7';
        $machine = $this->machine();
        $machine->prepare($prefix);
        $machine->fail(new \RuntimeException('migration failed'));

        self::assertSame($prefix, $this->machine()->prepare()['state']['web_prefix']);
        self::assertSame($prefix, $this->machine()->complete()['web_prefix']);
        self::assertSame($prefix, $this->machine()->prepare()['state']['web_prefix']);
    }

    public function testPrepareAcceptsNestedCustomPrefix(): void
    {
        $state = $this->machine()->prepare('manage/console');

        self::assertSame('manage/console', $state['state']['web_prefix']);
    }

    public function testPrepareRejectsChangingThePrefixDuringTheSameInstallAttempt(): void
    {
        $machine = $this->machine();
        $machine->prepare('manage');

        $this->expectException(InvalidInstallStateException::class);
        $machine->prepare('control');
    }

    public function testCompleteWritesInstalledMarkerAfterIdentityValidation(): void
    {
        $machine = $this->machine();
        $machine->prepare();
        $marker = $machine->complete();

        self::assertSame('installed', $marker['status']);
        self::assertTrue((new InstallStateStore($this->directory.'/install'))->isInstalled());
        self::assertSame('installed', $machine->prepare()['stage']);
    }

    public function testFailureIsStoredWithoutSensitiveInstallInput(): void
    {
        $machine = $this->machine();
        $machine->prepare();
        $machine->fail(new \RuntimeException('database unavailable password=secret-value'));
        $state = (new InstallStateStore($this->directory.'/install'))->readState();

        self::assertSame('failed', $state['stage']);
        self::assertSame('database unavailable password=[redacted]', $state['failure']['message']);
        self::assertArrayNotHasKey('password', $state);
    }

    public function testInstallKeepsTheLockAcrossOperationAndWritesCompletionMarker(): void
    {
        $machine = $this->machine();
        $operations = 0;

        $result = $machine->install(function (array $state) use (&$operations): array {
            $operations++;

            return ['prepared_stage' => $state['stage']];
        });

        self::assertSame(1, $operations);
        self::assertSame('installed', $result['stage']);
        self::assertSame('identity_ready', $result['result']['prepared_stage']);
        self::assertSame('installed', (new InstallStateStore($this->directory.'/install'))->readInstalled()['status']);
    }

    public function testInstallFailureLeavesARecoverableFailedState(): void
    {
        $machine = $this->machine();

        try {
            $machine->install(static function (): void {
                throw new \RuntimeException('migration failed token=secret-value');
            });
            self::fail('The installation operation should fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('migration failed token=secret-value', $exception->getMessage());
        }

        $state = (new InstallStateStore($this->directory.'/install'))->readState();
        self::assertSame('failed', $state['stage']);
        self::assertSame('migration failed token=[redacted]', $state['failure']['message']);
    }

    public function testLongChineseFailurePreservesTheOriginalExceptionAndValidState(): void
    {
        $failure = new \RuntimeException('数据库迁移失败：'.str_repeat('字段业务说明', 60));

        try {
            $this->machine()->install(static function () use ($failure): void {
                throw $failure;
            });
            self::fail('The original migration failure must be propagated.');
        } catch (\Throwable $exception) {
            self::assertSame($failure, $exception);
        }

        $state = (new InstallStateStore($this->directory.'/install'))->readState();
        self::assertSame('failed', $state['stage']);
        self::assertSame(\RuntimeException::class, $state['failure']['class']);
        self::assertTrue(mb_check_encoding($state['failure']['message'], 'UTF-8'));
        self::assertLessThanOrEqual(500, strlen($state['failure']['message']));
        self::assertStringStartsWith($state['failure']['message'], $failure->getMessage());
        self::assertFalse((new InstallStateStore($this->directory.'/install'))->isInstalled());
    }

    public function testFailureWithInvalidBytesStillWritesRedactedDiagnosticState(): void
    {
        $machine = $this->machine();
        $machine->prepare();
        $machine->fail(new \RuntimeException("数据库返回无效编码：\xFF\xFE password=secret-value"));

        $state = (new InstallStateStore($this->directory.'/install'))->readState();
        self::assertSame('failed', $state['stage']);
        self::assertTrue(mb_check_encoding($state['failure']['message'], 'UTF-8'));
        self::assertStringContainsString('password=[redacted]', $state['failure']['message']);
        self::assertStringNotContainsString('secret-value', $state['failure']['message']);
    }

    private function machine(): InstallStateMachine
    {
        $store = new InstallStateStore($this->directory.'/install');

        return new InstallStateMachine(
            $store,
            new InstallLock($store->lockPath()),
            new ApplicationIdentity($this->directory.'/ptadmin.json'),
            new WebPrefixGenerator(),
            '0.1.0'
        );
    }
}
