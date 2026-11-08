<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Updates;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Updates\{UpdateFiles, UpdateRecovery};
use Symfony\Component\Process\Process;

/** 使用真实子进程及文件切换，Composer/Artisan 仅由可控夹具替代，避免联网或触碰用户数据库。 */
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
        foreach (['UpdateFiles', 'UpdateRecovery', 'CoreUpdateRunner'] as $class) {
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
        file_put_contents($this->directory.'/composer.php', <<<'CODE'
<?php
if ($argv[1] !== 'update') { exit(0); }
$root = dirname(__DIR__, 3);
if (is_file($root.'/composer.fail')) { exit(1); }
$commit = is_file($root.'/commit.fail') ? str_repeat('f', 40) : str_repeat('a', 40);
file_put_contents('composer.lock', json_encode(['packages' => [['name' => 'ptadmin/core', 'version' => '1.1.0', 'source' => ['reference' => $commit]]]]));
mkdir('vendor');
file_put_contents('vendor/identity', 'updated');
CODE);
        // Composer 的工作目录为 operations/update/project，夹具脚本自身位于项目根。
        $composer = file_get_contents($this->directory.'/composer.php');
        file_put_contents($this->directory.'/composer.php', str_replace('$root = dirname(__DIR__, 3);', '$root = __DIR__;', $composer));
        file_put_contents($this->directory.'/artisan', <<<'CODE'
<?php
file_put_contents(__DIR__.'/commands.jsonl', json_encode(array_slice($argv, 1))."\n", FILE_APPEND);
$command = $argv[1];
if (is_file(__DIR__.'/fail-command') && trim(file_get_contents(__DIR__.'/fail-command')) === $command) { exit(1); }
if ($command === 'migrate') {
    if (!is_file(__DIR__.'/storage/framework/maintenance.php') || file_get_contents(__DIR__.'/vendor/identity') !== 'updated') { exit(2); }
    file_put_contents(__DIR__.'/migration.done', 'new database');
}
CODE);
        $inputs = [];
        foreach (['composer.json', 'composer.lock'] as $path) {
            $inputs[$path] = hash_file('sha256', $this->directory.'/'.$path);
        }
        UpdateFiles::write($this->operation.'/plan.json', ['root' => $this->directory, 'storage' => $this->directory.'/storage', 'version' => '1.1.0',
            'git_commit' => str_repeat('a', 40), 'php' => PHP_BINARY, 'composer' => $this->directory.'/composer.php', 'inputs' => $inputs,
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
        self::assertFileExists($this->operation.'/backup/3/old.php');
        self::assertFileDoesNotExist($this->directory.'/storage/framework/down');
        self::assertFileDoesNotExist($this->directory.'/storage/framework/maintenance.php');
        self::assertSame('succeeded', UpdateFiles::read($this->directory.'/tasks/test/state.json')['status']);
        $commands = file_get_contents($this->directory.'/commands.jsonl');
        self::assertStringContainsString('ptadmin:updates:verify-target', $commands);
        self::assertStringContainsString('ptadmin:sync', $commands);
        self::assertStringContainsString('route:cache', $commands);
        self::assertStringContainsString('queue:restart', $commands);
    }

    public function testRealOfflineComposerResolvesDependencyGraphAndAutoloadAfterVendorMove(): void
    {
        $composer = (new \PTAdmin\Core\Updates\UpdateEnvironment())->findComposer();
        if ($composer === null) {
            self::markTestSkipped('真实 Composer 不可用；其余独立进程与恢复测试仍执行。');
        }
        $packages = [];
        foreach (['1.0.0', '1.1.0'] as $version) {
            foreach (['ptadmin/core', 'fixture/dependency'] as $name) {
                $short = $name === 'ptadmin/core' ? 'Core' : 'Dependency';
                $path = $this->directory.'/'.$short.'-'.$version.'.zip';
                $definition = ['name' => $name, 'version' => $version, 'type' => 'library',
                    'autoload' => ['psr-4' => ['Fixture\\'.$short.'\\' => 'src/']]];
                if ($name === 'ptadmin/core') {
                    $definition['require'] = ['fixture/dependency' => $version];
                }
                $zip = new \ZipArchive();
                $zip->open($path, \ZipArchive::CREATE);
                $zip->addFromString('composer.json', json_encode($definition));
                $zip->addFromString('src/Identity.php', '<?php namespace Fixture\\'.$short.'; final class Identity { public const VERSION = "'.$version.'"; }');
                $zip->close();
                $packages[] = $definition + ['dist' => ['type' => 'zip', 'url' => 'file://'.$path, 'reference' => str_repeat('a', 40)],
                    'source' => ['type' => 'git', 'url' => 'https://example.invalid/unused.git', 'reference' => str_repeat('a', 40)]];
            }
        }
        $project = ['name' => 'fixture/host', 'require' => ['php' => '^8.2', 'ptadmin/core' => '1.0.0'],
            'repositories' => [['type' => 'package', 'package' => $packages], ['packagist.org' => false]],
            'autoload' => ['psr-4' => ['App\\' => 'app/']]];
        UpdateFiles::write($this->directory.'/composer.json', $project);
        file_put_contents($this->directory.'/app/UserAsset.php', '<?php namespace App; final class UserAsset { public const VALUE = "preserved"; }');
        unlink($this->directory.'/composer.lock');
        $environment = ['COMPOSER_HOME' => $this->directory.'/composer-home', 'COMPOSER_CACHE_DIR' => $this->directory.'/composer-cache',
            'COMPOSER_DISABLE_NETWORK' => '1', 'COMPOSER_ALLOW_SUPERUSER' => '1'];
        $install = new Process([PHP_BINARY, $composer, 'update', '--no-scripts', '--no-plugins', '--no-audit', '--no-interaction'], $this->directory, $environment);
        $install->setTimeout(60)->run();
        self::assertTrue($install->isSuccessful(), $install->getErrorOutput());
        $plan = UpdateFiles::read($this->operation.'/plan.json');
        $plan['composer'] = $composer;
        foreach (['composer.json', 'composer.lock'] as $file) {
            $plan['inputs'][$file] = hash_file('sha256', $this->directory.'/'.$file);
        }
        UpdateFiles::write($this->operation.'/plan.json', $plan);
        // 真实 Composer 生成的 vendor 不含虚构 identity 文件，改由自动加载结果验证。
        file_put_contents($this->directory.'/artisan', '<?php file_put_contents(__DIR__."/commands.jsonl", $argv[1]."\n", FILE_APPEND);');
        file_put_contents($this->operation.'/health.php', <<<'CODE'
<?php
require $argv[1].'/vendor/autoload.php';
if (\Fixture\Core\Identity::VERSION !== '1.1.0' || \Fixture\Dependency\Identity::VERSION !== '1.1.0' || \App\UserAsset::VALUE !== 'preserved') { exit(1); }
CODE);
        $process = new Process([PHP_BINARY, $this->operation.'/recover.php', 'run'], $this->directory, $environment);
        $process->setTimeout(60)->run();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $lock = UpdateFiles::read($this->directory.'/composer.lock');
        self::assertSame(['1.1.0', '1.1.0'], array_column($lock['packages'], 'version'));
        self::assertSame('succeeded', UpdateFiles::read($this->directory.'/tasks/test/state.json')['status']);
    }

    public function testComposerFailureAndCommitMismatchLeaveCurrentDependenciesUntouched(): void
    {
        file_put_contents($this->directory.'/composer.fail', '1');
        self::assertFalse($this->runUpdate()->isSuccessful());
        self::assertSame('original', file_get_contents($this->directory.'/vendor/identity'));
        self::assertDirectoryDoesNotExist($this->operation.'/backup');
        self::assertSame('failed', UpdateFiles::read($this->directory.'/tasks/test/state.json')['status']);
    }

    public function testCommitMismatchCannotBePromoted(): void
    {
        file_put_contents($this->directory.'/commit.fail', '1');
        $process = $this->runUpdate();
        self::assertFalse($process->isSuccessful());
        self::assertStringContainsString('提交与官方记录不一致', $process->getErrorOutput());
        self::assertSame('original', file_get_contents($this->directory.'/vendor/identity'));
        self::assertFileDoesNotExist($this->directory.'/storage/framework/down');
    }

    public function testFailureBeforeMigrationAutomaticallyRestoresDependenciesAndCachedFiles(): void
    {
        file_put_contents($this->directory.'/fail-command', 'package:discover');
        self::assertFalse($this->runUpdate()->isSuccessful());
        self::assertSame('original', file_get_contents($this->directory.'/vendor/identity'));
        self::assertSame('^1.0', UpdateFiles::read($this->directory.'/composer.json')['require']['ptadmin/core']);
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
        $restore = new Process([PHP_BINARY, $this->operation.'/recover.php', 'restore', '--database-restored']);
        self::assertSame(0, $restore->run(), $restore->getErrorOutput());
        self::assertSame('original', file_get_contents($this->directory.'/vendor/identity'));
        self::assertFileDoesNotExist($this->directory.'/storage/framework/maintenance.php');
        self::assertSame('restored', UpdateFiles::read($this->directory.'/tasks/test/state.json')['stage']);
        self::assertTrue(UpdateFiles::read($this->directory.'/tasks/test/result.json')['data']['recovery_completed']);
    }
}
