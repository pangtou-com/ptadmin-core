<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Updates;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Updates\{UpdateFiles, UpdateRecovery};

final class UpdateRecoveryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ptadmin-recovery-'.bin2hex(random_bytes(6));
        UpdateFiles::directory($this->directory);
        file_put_contents($this->directory.'/old', 'original');
        file_put_contents($this->directory.'/new', 'updated');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
    }

    public function testSwitchAndIdempotentRecoveryPreserveOriginalFiles(): void
    {
        $recovery = new UpdateRecovery($this->directory.'/operation');
        $recovery->switch([['source' => $this->directory.'/new', 'target' => $this->directory.'/old']]);
        self::assertSame('updated', file_get_contents($this->directory.'/old'));
        self::assertSame('original', file_get_contents($this->directory.'/operation/backup/0'));
        $recovery->restore();
        $recovery->restore();
        self::assertSame('original', file_get_contents($this->directory.'/old'));
        self::assertSame('restored', $recovery->read()['status']);
    }

    public function testInterruptedPreparationWithoutJournalDoesNotTouchRuntimeFiles(): void
    {
        $operation = $this->directory.'/operation';
        UpdateFiles::write($operation.'/operation.json', []);
        $journal = (new UpdateRecovery($operation))->restore();
        self::assertSame('restored', $journal['status']);
        self::assertSame('original', file_get_contents($this->directory.'/old'));
        self::assertSame('updated', file_get_contents($this->directory.'/new'));
    }

    public function testCrashBetweenBackupAndPromotionCanRecoverWithoutLaravel(): void
    {
        $operation = $this->directory.'/operation';
        UpdateFiles::directory($operation.'/backup');
        UpdateFiles::write($operation.'/journal.json', ['status' => 'switching', 'entries' => [
            ['source' => $this->directory.'/new', 'target' => $this->directory.'/old', 'backup' => $operation.'/backup/0', 'restored' => false],
        ]]);
        rename($this->directory.'/old', $operation.'/backup/0');
        (new UpdateRecovery($operation))->restore();
        self::assertSame('original', file_get_contents($this->directory.'/old'));
        self::assertSame('updated', file_get_contents($this->directory.'/new'));
    }

    public function testMigrationFailureRequiresDatabaseRestorationAndCompletedBackupCannotOverwriteLaterFiles(): void
    {
        $recovery = new UpdateRecovery($this->directory.'/operation');
        $recovery->switch([['source' => $this->directory.'/new', 'target' => $this->directory.'/old']]);
        $recovery->stage('migrating', ['database_started' => true]);
        try {
            $recovery->restore();
            self::fail('必须先恢复数据库');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('--database-restored', $error->getMessage());
        }
        self::assertSame('updated', file_get_contents($this->directory.'/old'));
        $recovery->restore(true);
        self::assertSame('original', file_get_contents($this->directory.'/old'));
        $recovery->stage('completed');
        $this->expectExceptionMessage('该更新已完成');
        $recovery->restore(true);
    }
}
