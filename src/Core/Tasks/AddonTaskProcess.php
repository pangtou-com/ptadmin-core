<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tasks;

use Symfony\Component\Process\Process;

/** 插件包任务使用独立进程，启动时不加载即将被替换的插件类。 */
final class AddonTaskProcess
{
    public function __construct(public readonly ?string $taskId = null, public readonly ?string $addonCode = null)
    {
    }

    /** @param array<string, mixed> $task */
    public static function target(array $task): ?string
    {
        $payload = $task['payload'] ?? [];
        $local = in_array($task['type'], [TaskManager::ADDON_INSTALL, TaskManager::ADDON_UPDATE], true);
        $cloud = $task['type'] === TaskManager::CLOUD_PACKAGE
            && ($payload['product_type'] ?? null) === 'addon'
            && in_array($payload['operation'] ?? null, ['install', 'update'], true);
        if (!$local && !$cloud) {
            return null;
        }
        $code = $payload['code'] ?? null;
        if ($code === null && $local) {
            // 本地包允许省略 code；这里只读取启动排除提示，正式签名与清单校验仍由安装器完成。
            // 无法识别时仍进入独立进程，让安装器在原校验阶段报告具体包错误。
            return self::localPackageCode((string) ($payload['source'] ?? ''));
        }
        if (!is_string($code) || preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $code) !== 1) {
            throw new TaskException('插件安装任务缺少有效的插件编码。', 'invalid_addon_code');
        }

        return $code;
    }

    private static function localPackageCode(string $source): string
    {
        $source = realpath($source);
        if ($source === false) {
            return '';
        }
        $contents = false;
        if (is_dir($source) && is_file($source.'/manifest.json')) {
            $contents = file_get_contents($source.'/manifest.json', length: 1048577);
        } elseif (is_file($source) && class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($source) === true) {
                try {
                    $contents = $zip->getFromName('manifest.json', 1048577);
                } finally {
                    $zip->close();
                }
            }
        }
        $manifest = is_string($contents) ? json_decode($contents, true) : null;
        $code = is_array($manifest) ? ($manifest['code'] ?? null) : null;
        return is_string($code) && preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $code) === 1 ? $code : '';
    }

    /** 子进程直接读取私有任务文件，命令行不传递授权凭据或安装包信任对象。 */
    public function run(string $taskId): void
    {
        $process = new Process([
            (string) config('ptadmin-updates.php_binary', PHP_BINDIR.'/php'),
            __DIR__.'/../../../resources/bin/addon-task.php',
            base_path(),
            $taskId,
        ], base_path(), timeout: null);
        // 任务结果和异常由子进程写入文件；不将任意插件输出回显给 HTTP 调用方。
        $process->disableOutput();
        if ($process->run() !== 0) {
            throw new TaskException('插件任务独立进程执行失败，请查看任务记录和宿主日志。', 'addon_process_failed');
        }
    }
}
