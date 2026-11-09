<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

use Symfony\Component\Process\Process;

/** 只在准备 Core 更新时探测执行能力，输出不包含子进程的环境和凭据。 */
final class UpdateRuntimeProbe
{
    public function assertUsable(string $php, string $composer): void
    {
        $probe = $this->run([$php, '-r', 'echo json_encode([PHP_SAPI, PHP_VERSION, get_loaded_extensions()]);'], 'PHP CLI');
        $runtime = json_decode($probe->getOutput(), true);
        if (!is_array($runtime) || ($runtime[0] ?? null) !== 'cli' || !is_string($runtime[1] ?? null) || !is_array($runtime[2] ?? null)) {
            throw new UpdateException('配置的 PHP 未返回有效 CLI 信息，请核对 PTADMIN_UPDATE_PHP_BINARY，或由部署负责人在终端更新。', 'php_cli_unavailable');
        }
        $issues = $this->runtimeIssues(PHP_VERSION, get_loaded_extensions(), $runtime[1], $runtime[2]);
        if ($issues !== []) {
            throw new UpdateException(implode(' ', $issues).' 请核对 CLI 配置和扩展，或在匹配的终端环境手动更新。', 'php_runtime_mismatch');
        }
        $this->run([$php, $composer, '--version', '--no-plugins', '--no-scripts', '--no-interaction'], 'Composer');
    }

    public function runtimeIssues(string $webVersion, array $webExtensions, string $cliVersion, array $cliExtensions): array
    {
        $issues = [];
        if ($webVersion !== $cliVersion) {
            $issues[] = 'PHP 版本不一致：网页 '.$webVersion.'，CLI '.$cliVersion.'。';
        }
        // SAPI 标识并非业务扩展；调试器和字节码缓存也可按运行方式分别启用。
        $ignored = ['cgi-fcgi', 'apache2handler', 'cli', 'cli-server', 'phpdbg', 'litespeed', 'zend opcache', 'xdebug'];
        $web = array_diff(array_map('strtolower', $webExtensions), $ignored);
        $cli = array_diff(array_map('strtolower', $cliExtensions), $ignored);
        if ($missing = array_diff($web, $cli)) {
            $issues[] = 'PHP CLI 缺少扩展：'.implode('、', $missing).'。';
        }
        if ($extra = array_diff($cli, $web)) {
            $issues[] = '网页 PHP 缺少扩展：'.implode('、', $extra).'。';
        }
        return $issues;
    }

    private function run(array $command, string $label): Process
    {
        try {
            $process = new Process($command, base_path());
            $process->setTimeout(10)->run();
        } catch (\Throwable) {
            throw new UpdateException($label.' 无法启动或探测超时，请在服务器终端检查执行权限和配置路径，并按手动更新指引处理。', 'runtime_unavailable');
        }
        if (!$process->isSuccessful()) {
            throw new UpdateException($label.' 执行失败（退出码 '.$process->getExitCode().'），请由有部署权限的账号在终端检查环境后手动更新。', 'runtime_unavailable');
        }
        return $process;
    }
}
