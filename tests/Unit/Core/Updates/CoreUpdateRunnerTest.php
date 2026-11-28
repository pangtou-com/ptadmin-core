<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Updates;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Updates\{UpdateFiles, UpdateRecovery, PackageManifest, ApplicationPackageBuilder, ApplicationPackage};
use Symfony\Component\Process\Process;

/** 使用真实子进程及文件切换，目标包为真实归档，Artisan 使用可控夹具，避免联网或触碰用户数据库。 */
final class CoreUpdateRunnerTest extends TestCase
{
    private string $directory;
    private string $operation;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ptadmin-core-runner-'.bin2hex(random_bytes(6));
        $this->operation = $this->directory.'/operations/update';
        foreach (['operations/update', 'vendor', 'bootstrap/cache', 'storage/framework', 'tasks/test', 'app'] as $path) {
            UpdateFiles::directory($this->directory.'/'.$path);
        }
        $source = dirname(__DIR__, 4);
        foreach (['UpdateFiles', 'UpdateRecovery', 'CoreUpdateRunner', 'PackageManifest', 'ReleaseVersion'] as $class) {
            copy($source.'/src/Core/Updates/'.$class.'.php', $this->operation.'/'.$class.'.php');
        }
        copy($source.'/resources/update-recovery.php', $this->operation.'/recover.php');
        file_put_contents($this->operation.'/health.php', '<?php if (!is_file($argv[1]."/migration.done")) { exit(1); }');
        UpdateFiles::write($this->directory.'/composer.json', ['require' => ['ptadmin/core' => '^1.0'], 'autoload' => ['psr-4' => ['App\\' => 'app/']]]);
        UpdateFiles::write($this->directory.'/composer.lock', ['packages' => [['name' => 'ptadmin/core', 'version' => '1.0.0']]]);
        file_put_contents($this->directory.'/vendor/identity', 'original');
        file_put_contents($this->directory.'/bootstrap/cache/old.php', 'old cache');
        file_put_contents($this->directory.'/.env', 'APP_KEY=keep');
        file_put_contents($this->directory.'/storage/user.txt', 'keep');
        file_put_contents($this->directory.'/artisan', <<<'CODE'
<?php
file_put_contents(__DIR__.'/commands.jsonl', json_encode(array_slice($argv, 1))."\n", FILE_APPEND);
$command = $argv[1];
if ($command === 'ptadmin:updates:verify-target' && in_array('--publication-only', $argv, true) && is_file(__DIR__.'/withdraw-after-drain')) { exit(1); }
if (is_file(__DIR__.'/fail-command') && trim(file_get_contents(__DIR__.'/fail-command')) === $command) { exit(1); }
if ($command === 'migrate') {
    if (!is_file(__DIR__.'/storage/framework/maintenance.php') || file_get_contents(__DIR__.'/vendor/identity') !== 'updated') { exit(2); }
    file_put_contents(__DIR__.'/migration.done', 'new database');
}
CODE);
        foreach (['original' => '1.0.0', 'updated' => '1.1.0'] as $identity => $version) {
            $root = $identity === 'original' ? $this->directory : $this->directory.'/build-source';
            $package = ['name' => 'ptadmin/core', 'version' => $version, 'source' => ['reference' => str_repeat('a', 40)]];
            UpdateFiles::write($root.'/composer.json', ['require' => ['ptadmin/core' => $version], 'autoload' => ['psr-4' => ['App\\' => 'app/']]]);
            UpdateFiles::write($root.'/composer.lock', ['packages' => [$package]]);
            UpdateFiles::write($root.'/vendor/composer/installed.json', ['dev' => false, 'packages' => [$package]]);
            $shellRoot = $root.'/vendor/ptadmin/core/resources/admin-frontend';
            UpdateFiles::directory($shellRoot);
            file_put_contents($shellRoot.'/index.html', 'shell');
            $shellFiles = [['path' => 'index.html', 'size' => 5, 'sha256' => hash('sha256', 'shell')]];
            UpdateFiles::write($shellRoot.'/manifest.json', ['manifest_version' => 1, 'frontend_version' => $version,
                'entry' => './index.html', 'files' => $shellFiles, 'artifact_sha256' => hash('sha256', json_encode($shellFiles, JSON_UNESCAPED_SLASHES)),
                'backend_constraint' => '^1.0', 'api_protocol' => 1, 'schema_protocol' => 1]);
            file_put_contents($root.'/vendor/identity', $identity);
            foreach (['autoload.php', 'composer/installed.php', 'composer/InstalledVersions.php'] as $file) {
                file_put_contents($root.'/vendor/'.$file, '<?php');
            }
            foreach (['public/index.php', 'bootstrap/update-guard.php', 'bootstrap/app.php', 'config/auth.php'] as $file) {
                UpdateFiles::directory(dirname($root.'/'.$file));
                file_put_contents($root.'/'.$file, '<?php // host');
            }
            if ($identity === 'updated') {
                copy($this->directory.'/artisan', $root.'/artisan');
            }
            (new ApplicationPackageBuilder())->build($root, $this->directory.'/'.$identity.'-release', $version, str_repeat('b', 40), '^1.0');
        }
        $packages = new ApplicationPackage();
        $packages->extract($this->directory.'/updated-release/full.zip', $this->operation.'/bundle');
        $packages->prepare($this->operation.'/bundle', $this->operation.'/project');
        $inputs = [];
        foreach (['composer.json', 'composer.lock', PackageManifest::IDENTITY] as $path) {
            $inputs[$path] = hash_file('sha256', $this->directory.'/'.$path);
        }
        UpdateFiles::write($this->operation.'/plan.json', ['root' => $this->directory, 'storage' => $this->directory.'/storage', 'version' => '1.1.0',
            'git_commit' => str_repeat('a', 40), 'php' => PHP_BINARY, 'inputs' => $inputs,
            'timeout' => 15, 'task_directory' => $this->directory.'/tasks/test', 'config_cached' => true, 'rebuild_caches' => ['route:cache']]);
        UpdateFiles::write($this->directory.'/tasks/test/state.json', ['task_id' => 'test', 'type' => 'core.update', 'stage' => 'starting', 'status' => 'running', 'progress' => 0, 'request_id' => null]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
    }

    private function runUpdate(): Process
    {
        $process = new Process([PHP_BINARY, $this->operation.'/recover.php', 'run']);
        $process->setTimeout(30)->run();
        return $process;
    }

    private function useComposerSource(): void
    {
        $target = UpdateFiles::read($this->directory.'/'.PackageManifest::IDENTITY);
        unlink($this->directory.'/'.PackageManifest::IDENTITY);
        file_put_contents($this->directory.'/vendor/autoload.php', '<?php // locally generated');
        $snapshot = PackageManifest::verifyComposerInstallation($this->directory, $target);
        $plan = UpdateFiles::read($this->operation.'/plan.json');
        unset($plan['inputs'][PackageManifest::IDENTITY]);
        $plan['composer_source'] = ['target' => $target, 'files' => $snapshot['files'], 'identity_sha256' => null];
        UpdateFiles::write($this->operation.'/plan.json', $plan);
    }

    public function testComposerSourceCreatesOfficialIdentityOnlyAfterSuccessfulSwitch(): void
    {
        $this->useComposerSource();
        $process = $this->runUpdate();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertSame('1.1.0', UpdateFiles::read($this->directory.'/'.PackageManifest::IDENTITY)['version']);
        self::assertSame('original', file_get_contents($this->operation.'/backup/0/identity'));
        self::assertSame('standard', (new \PTAdmin\Core\Updates\DependencyBaseline())->inspect($this->directory, true)['status']);
    }

    public function testComposerSourceFailureRestoresOriginalAbsenceOfOfficialIdentity(): void
    {
        $this->useComposerSource();
        file_put_contents($this->directory.'/fail-command', 'package:discover');
        self::assertFalse($this->runUpdate()->isSuccessful());
        self::assertSame('original', file_get_contents($this->directory.'/vendor/identity'));
        self::assertFileDoesNotExist($this->directory.'/'.PackageManifest::IDENTITY);
        self::assertFileDoesNotExist($this->directory.'/storage/framework/down');
        self::assertSame('restored', UpdateFiles::read($this->operation.'/journal.json')['status']);
    }

    public function testComposerSourceMutationAfterPreparationCannotBeOverwritten(): void
    {
        $this->useComposerSource();
        file_put_contents($this->directory.'/vendor/autoload.php', '<?php // modified after preparation');
        $process = $this->runUpdate();
        self::assertFalse($process->isSuccessful());
        self::assertStringContainsString('核验后依赖文件发生变化', $process->getErrorOutput());
        self::assertSame('original', file_get_contents($this->directory.'/vendor/identity'));
        self::assertFileDoesNotExist($this->directory.'/'.PackageManifest::IDENTITY);
        self::assertDirectoryDoesNotExist($this->operation.'/backup');
    }

    public function testCompleteUpgradeSwitchesWholeDependenciesRunsMigrationAndPreservesUserFiles(): void
    {
        $process = $this->runUpdate();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertSame('updated', file_get_contents($this->directory.'/vendor/identity'));
        self::assertSame('original', file_get_contents($this->operation.'/backup/0/identity'));
        self::assertSame('1.1.0', UpdateFiles::read($this->directory.'/composer.json')['require']['ptadmin/core']);
        self::assertSame('keep', file_get_contents($this->directory.'/storage/user.txt'));
        self::assertSame('APP_KEY=keep', file_get_contents($this->directory.'/.env'));
        self::assertFileDoesNotExist($this->directory.'/bootstrap/cache/old.php');
        self::assertFileExists($this->operation.'/backup/4/old.php');
        self::assertFileDoesNotExist($this->directory.'/storage/framework/down');
        self::assertFileDoesNotExist($this->directory.'/storage/framework/maintenance.php');
        self::assertSame('succeeded', UpdateFiles::read($this->directory.'/tasks/test/state.json')['status']);
        $commands = file_get_contents($this->directory.'/commands.jsonl');
        self::assertStringContainsString('ptadmin:updates:verify-target', $commands);
        self::assertStringContainsString('ptadmin:sync', $commands);
        self::assertStringContainsString('route:cache', $commands);
        self::assertStringContainsString('queue:restart', $commands);
    }

    public function testTamperedPreparedTargetDoesNotChangeCurrentDependencies(): void
    {
        file_put_contents($this->operation.'/project/vendor/identity', 'tampered');
        self::assertFalse($this->runUpdate()->isSuccessful());
        self::assertSame('original', file_get_contents($this->directory.'/vendor/identity'));
        self::assertDirectoryDoesNotExist($this->operation.'/backup');
        self::assertSame('failed', UpdateFiles::read($this->directory.'/tasks/test/state.json')['status']);
    }

    public function testRunnerWaitsForExistingExecutionBeforeReplacingDependencies(): void
    {
        $lock = fopen($this->directory.'/storage/framework/ptadmin-runtime.lock', 'c+');
        flock($lock, LOCK_SH);
        $process = new Process([PHP_BINARY, $this->operation.'/recover.php', 'run']);
        $process->start();
        try {
            $deadline = microtime(true) + 5;
            while (!is_file($this->directory.'/storage/framework/down') && microtime(true) < $deadline) {
                usleep(20000);
                clearstatcache();
            }
            self::assertFileExists($this->directory.'/storage/framework/down');
            self::assertSame('original', file_get_contents($this->directory.'/vendor/identity'));
            self::assertTrue($process->isRunning());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::assertSame(0, $process->wait(), $process->getErrorOutput());
        self::assertSame('updated', file_get_contents($this->directory.'/vendor/identity'));
    }

    public function testCommitMismatchCannotBePromoted(): void
    {
        $plan = UpdateFiles::read($this->operation.'/plan.json');
        $plan['git_commit'] = str_repeat('f', 40);
        UpdateFiles::write($this->operation.'/plan.json', $plan);
        $process = $this->runUpdate();
        self::assertFalse($process->isSuccessful());
        self::assertStringContainsString('官方发行身份不一致', $process->getErrorOutput());
        self::assertSame('original', file_get_contents($this->directory.'/vendor/identity'));
        self::assertFileDoesNotExist($this->directory.'/storage/framework/down');
    }

    public function testPublicationWithdrawalAfterDrainRestoresAccessWithoutSwitching(): void
    {
        file_put_contents($this->directory.'/withdraw-after-drain', 'withdrawn');
        self::assertFalse($this->runUpdate()->isSuccessful());
        self::assertSame('original', file_get_contents($this->directory.'/vendor/identity'));
        self::assertFileDoesNotExist($this->directory.'/migration.done');
        self::assertFileDoesNotExist($this->directory.'/storage/framework/down');
        self::assertSame('restored', UpdateFiles::read($this->operation.'/journal.json')['status']);
    }

    public function testFailureBeforeMigrationAutomaticallyRestoresDependenciesAndCachedFiles(): void
    {
        file_put_contents($this->directory.'/fail-command', 'package:discover');
        self::assertFalse($this->runUpdate()->isSuccessful());
        self::assertSame('original', file_get_contents($this->directory.'/vendor/identity'));
        self::assertSame('1.0.0', UpdateFiles::read($this->directory.'/composer.json')['require']['ptadmin/core']);
        self::assertFileExists($this->directory.'/bootstrap/cache/old.php');
        self::assertFileDoesNotExist($this->directory.'/storage/framework/down');
        self::assertSame('restored', UpdateFiles::read($this->operation.'/journal.json')['status']);
    }

    public function testMigrationFailureKeepsMaintenanceAndStandaloneRestoreRequiresDatabaseConfirmation(): void
    {
        file_put_contents($this->directory.'/fail-command', 'ptadmin:sync');
        self::assertFalse($this->runUpdate()->isSuccessful());
        self::assertSame('updated', file_get_contents($this->directory.'/vendor/identity'));
        self::assertFileExists($this->directory.'/migration.done');
        self::assertFileExists($this->directory.'/storage/framework/maintenance.php');
        $restore = new Process([PHP_BINARY, $this->operation.'/recover.php', 'restore']);
        self::assertSame(1, $restore->run());
        self::assertStringContainsString('--database-restored', $restore->getErrorOutput());
        $runtimeLock = fopen($this->directory.'/storage/framework/ptadmin-runtime.lock', 'c+');
        flock($runtimeLock, LOCK_SH);
        try {
            $busy = new Process([PHP_BINARY, $this->operation.'/recover.php', 'restore', '--database-restored']);
            self::assertSame(1, $busy->run());
            self::assertStringContainsString('仍有请求或命令运行', $busy->getErrorOutput());
            self::assertSame('updated', file_get_contents($this->directory.'/vendor/identity'));
        } finally {
            flock($runtimeLock, LOCK_UN);
            fclose($runtimeLock);
        }
        $restore = new Process([PHP_BINARY, $this->operation.'/recover.php', 'restore', '--database-restored']);
        self::assertSame(0, $restore->run(), $restore->getErrorOutput());
        self::assertSame('original', file_get_contents($this->directory.'/vendor/identity'));
        self::assertFileDoesNotExist($this->directory.'/storage/framework/maintenance.php');
        self::assertSame('restored', UpdateFiles::read($this->directory.'/tasks/test/state.json')['stage']);
        self::assertTrue(UpdateFiles::read($this->directory.'/tasks/test/result.json')['data']['recovery_completed']);
    }
}
