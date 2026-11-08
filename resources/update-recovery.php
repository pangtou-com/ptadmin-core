<?php

declare(strict_types=1);

// 此文件仅复制到 storage 私有更新目录，通过 PHP CLI 执行，不提供 HTTP 恢复接口。
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__.'/UpdateFiles.php';
require __DIR__.'/UpdateRecovery.php';
require __DIR__.'/CoreUpdateRunner.php';

use PTAdmin\Core\Updates\{CoreUpdateRunner, UpdateRecovery};

$lock = fopen(dirname(__DIR__).'/.execution.lock', 'c+');
try {
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('已有更新或恢复进程执行中。');
    }
    if (($argv[1] ?? '') === 'run') {
        (new CoreUpdateRunner(__DIR__))->execute();
    } elseif (($argv[1] ?? '') === 'restore') {
        (new UpdateRecovery(__DIR__))->restore(in_array('--database-restored', $argv, true), true);
        echo "文件已恢复，请核对数据库与文件版本一致，再检查应用运行状态。\n";
    } else {
        throw new RuntimeException('用法：php recover.php restore [--database-restored]');
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
