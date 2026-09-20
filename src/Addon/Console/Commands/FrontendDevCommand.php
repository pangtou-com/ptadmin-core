<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Console\Commands;

use Illuminate\Console\Command;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Addon\Frontend\FrontendDevelopmentStore;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;

final class FrontendDevCommand extends Command
{
    protected $signature = 'ptadmin:addon:frontend-dev {code} {url?}
        {--sandbox=iframe} {--revision=} {--pid=} {--run= : 启动并托管 Dev Server 的命令}
        {--working-dir= : Dev Server 命令工作目录} {--unregister}';

    protected $description = '注册或注销仅供 local/testing 使用的插件前端开发入口';

    public function __construct(private FrontendDevelopmentStore $store, private AddonCatalog $addons)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $code = (string) $this->argument('code');
        if ((bool) $this->option('unregister')) {
            $this->store->unregister($code);
            $this->info(sprintf('已注销插件 %s 的前端开发入口。', $code));
            return self::SUCCESS;
        }
        $url = (string) ($this->argument('url') ?? '');
        if ($url === '') {
            $this->error('注册开发入口时必须提供完整 URL。');
            return self::INVALID;
        }
        $addon = collect($this->addons->scan()['addons'])->firstWhere('code', $code);
        if (!is_array($addon) || ($addon['state'] ?? null) !== 'active') {
            $this->error(sprintf('插件 %s 不存在或未启用。', $code));
            return self::FAILURE;
        }
        $command = is_string($this->option('run')) ? trim($this->option('run')) : '';
        try {
            if ($command !== '') {
                return $this->runDevelopmentServer($code, $url, $command);
            }
            $registration = $this->register($code, $url);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
        $this->info(sprintf('已注册插件 %s 的前端开发入口，revision=%s。', $code, $registration['dev_revision']));
        return self::SUCCESS;
    }

    /** 启动开发服务后持续续租；命令结束时注销，异常退出则由租约过期兜底。 */
    private function runDevelopmentServer(string $code, string $url, string $command): int
    {
        $workingDirectory = is_string($this->option('working-dir'))
            ? trim($this->option('working-dir'))
            : '';
        if ($workingDirectory !== '') {
            $workingDirectory = realpath($workingDirectory) ?: '';
            if ($workingDirectory === '' || !is_dir($workingDirectory)) {
                throw new RuntimeException('Dev Server 工作目录不存在。');
            }
        }
        $process = Process::fromShellCommandline($command, $workingDirectory ?: null);
        $process->setTimeout(null);
        $process->start(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });
        $baseRevision = is_string($this->option('revision')) && $this->option('revision') !== ''
            ? $this->option('revision')
            : date('YmdHis').'-'.bin2hex(random_bytes(4));
        $revision = $baseRevision;
        $sourceDirectory = $workingDirectory !== '' ? $workingDirectory : null;
        $sourceFingerprint = is_string($sourceDirectory) ? $this->sourceFingerprint($sourceDirectory) : '';
        $sourceRevision = 0;
        $processId = $process->getPid();
        $registered = false;
        try {
            $registration = $this->register($code, $url, $revision, $processId);
            $registered = true;
            $this->info(sprintf(
                '已启动并注册插件 %s 的前端开发服务，pid=%s，revision=%s。',
                $code,
                (string) $processId,
                $registration['dev_revision'],
            ));
            $signals = array_values(array_filter([
                defined('SIGINT') ? SIGINT : null,
                defined('SIGTERM') ? SIGTERM : null,
            ], 'is_int'));
            if ($signals !== []) {
                $this->trap($signals, static function (int $signal) use ($process): void {
                    if ($process->isRunning()) {
                        $process->signal($signal);
                    }
                });
            }
            $nextHeartbeat = time() + $this->store->heartbeatSeconds();
            $nextSourceCheck = microtime(true) + 0.5;
            while ($process->isRunning()) {
                if (is_string($sourceDirectory) && microtime(true) >= $nextSourceCheck) {
                    $nextFingerprint = $this->sourceFingerprint($sourceDirectory);
                    if ($nextFingerprint !== $sourceFingerprint) {
                        $sourceFingerprint = $nextFingerprint;
                        $revision = $baseRevision.'-'.(++$sourceRevision);
                        $this->register($code, $url, $revision, $processId);
                        $nextHeartbeat = time() + $this->store->heartbeatSeconds();
                        $this->line(sprintf('检测到插件前端源码变化，revision=%s。', $revision));
                    }
                    $nextSourceCheck = microtime(true) + 0.5;
                }
                if (time() >= $nextHeartbeat) {
                    $this->register($code, $url, $revision, $processId);
                    $nextHeartbeat = time() + $this->store->heartbeatSeconds();
                }
                usleep(100_000);
            }
        } finally {
            if ($process->isRunning()) {
                $process->stop(5);
            }
            if ($registered) {
                $this->store->unregister($code);
            }
        }

        return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }

    private function sourceFingerprint(string $directory): string
    {
        $excludedDirectories = ['.git', '.vite', 'node_modules', 'vendor'];
        $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            static fn (SplFileInfo $file): bool => !$file->isDir()
                || !in_array($file->getFilename(), $excludedDirectories, true),
        ));
        $fingerprints = [];
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $digest = hash_file('sha256', $path);
            if (is_string($digest)) {
                $fingerprints[] = substr($path, strlen($directory)).':'.$digest;
            }
        }
        sort($fingerprints, SORT_STRING);

        return hash('sha256', implode("\n", $fingerprints));
    }

    /** @return array<string, mixed> */
    private function register(
        string $code,
        string $url,
        ?string $revision = null,
        ?int $processId = null,
    ): array {
        return $this->store->register(
            $code,
            $url,
            (string) $this->option('sandbox'),
            $revision ?? (is_string($this->option('revision')) ? $this->option('revision') : null),
            $processId ?? (is_numeric($this->option('pid')) ? (int) $this->option('pid') : null),
        );
    }
}
