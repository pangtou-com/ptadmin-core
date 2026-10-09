<?php

declare(strict_types=1);

namespace PTAdmin\Cloud\Console;

use Illuminate\Console\Command;
use Illuminate\Process\Factory as ProcessFactory;
use PTAdmin\Cloud\CloudException;
use PTAdmin\Cloud\CloudProtocol;
use PTAdmin\Cloud\CloudSessionManager;
use PTAdmin\Cloud\Contracts\CloudClientInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;

/** 本机项目运维入口，与后台共享加密云会话，平台仍逐次校验开发者权限。 */
final class CloudCommand extends Command
{
    protected $signature = 'ptadmin:cloud
        {action=status : login、status、logout 或 products}
        {--page=1 : 商品列表页码}
        {--no-browser : 登录时只显示授权链接，不询问打开浏览器}
        {--timeout=600 : 等待浏览器授权的秒数，1 至 600}';

    protected $description = '连接云市场并查看授权账户和开发者商品';

    public function handle(CloudClientInterface $client, CloudSessionManager $sessions, ProcessFactory $processes): int
    {
        try {
            switch ($this->argument('action')) {
                case 'login':
                    return $this->login($client, $sessions, $processes);
                case 'status':
                    $summary = $sessions->summary();
                    if (!$summary['authenticated']) {
                        $this->warn('尚未连接或授权已失效，请运行 php artisan ptadmin:cloud login。');
                        return self::FAILURE;
                    }
                    $this->line('账户：'.OutputFormatter::escape($this->maskedEmail($summary['user']['email'])));
                    $this->line('连接状态：已连接');
                    if (isset($summary['authorization_expires_at'])) {
                        $this->line('连接到期：'.date('Y-m-d H:i:s T', $summary['authorization_expires_at']));
                    }
                    return self::SUCCESS;
                case 'logout':
                    $sessions->logout();
                    $this->info('云市场连接已退出。');
                    return self::SUCCESS;
                case 'products':
                    $page = filter_var($this->option('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($page === false) {
                        throw new CloudException('商品页码必须为正整数。', 'invalid_request');
                    }
                    $products = $client->products($sessions->token(), ['page' => $page]);
                    $this->table(['商品 ID', '类型', '编码', '名称'], array_map(static fn (array $product): array => [
                        $product['id'], $product['product_type'], OutputFormatter::escape($product['code']), OutputFormatter::escape($product['title']),
                    ], $products['results']));
                    $this->line('共 '.$products['total'].' 个商品，当前第 '.$page.' 页（每页 50 条）。');
                    return self::SUCCESS;
                default:
                    throw new CloudException('未知操作，可用操作为 login、status、logout、products；版本发布请使用 ptadmin:publish。', 'invalid_request');
            }
        } catch (CloudException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }

    private function maskedEmail(string $email): string
    {
        [$localPart, $domain] = explode('@', $email, 2);
        $visibleLength = min(3, max(0, mb_strlen($localPart) - 1));
        return mb_substr($localPart, 0, $visibleLength).'****@'.$domain;
    }

    private function login(CloudClientInterface $client, CloudSessionManager $sessions, ProcessFactory $processes): int
    {
        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 600]]);
        if ($timeout === false) {
            throw new CloudException('等待授权时间必须为 1 至 600 秒。', 'invalid_request');
        }
        $verifier = bin2hex(random_bytes(32));
        $url = $client->authorizationUrl(['flow' => 'cli', 'redirect_uri' => '', 'state' => bin2hex(random_bytes(32)),
            'code_challenge' => CloudProtocol::challenge($verifier), 'code_challenge_method' => 'S256']);
        $key = basename($url);
        $this->line('请在浏览器打开以下链接，并核对终端确认标识：'.substr($key, 0, 8));
        $this->line('<href='.$url.'>'.OutputFormatter::escape($url).'</>');
        if ($this->input->isInteractive() && !$this->option('no-browser')
            && $this->confirm('按回车打开默认浏览器（输入 n 后手动打开链接）', true)) {
            $this->openBrowser($url, $processes);
        }
        $this->line('请在浏览器确认连接当前会员账户。正在等待确认，可按 Ctrl+C 取消等待。');
        $deadline = microtime(true) + $timeout;
        do {
            $result = $client->pollCliAuthorization($key, $verifier);
            if (($result['status'] ?? null) === 'authorized') {
                unset($result['status']);
                $sessions->store($result);
                $this->info('云市场连接成功，授权有效期为 2 小时。');
                return self::SUCCESS;
            }
            if (($result['status'] ?? null) !== 'pending') {
                throw new CloudException('平台返回的终端授权状态无效。', 'cloud_response_invalid');
            }
            $remaining = $deadline - microtime(true);
            if ($remaining > 0) {
                usleep((int) (min(5, $remaining) * 1000000));
            }
        } while (microtime(true) < $deadline);
        throw new CloudException('等待授权超时，请重新运行 php artisan ptadmin:cloud login。', 'cloud_authorization_timeout');
    }

    private function openBrowser(string $url, ProcessFactory $processes): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => ['open', $url],
            'Windows' => ['rundll32.exe', 'url.dll,FileProtocolHandler', $url],
            'Linux', 'BSD' => ['xdg-open', $url],
            default => null,
        };
        $opened = false;
        if ($command !== null) {
            try {
                // 参数数组交给 Process 转义，授权地址不拼接为 shell 命令。
                $opened = $processes->newPendingProcess()->timeout(5)->run($command)->successful();
            } catch (\RuntimeException | \LogicException) {
                // 无桌面、缺少系统打开器或超时不影响手动授权。
            }
        }
        if ($opened) {
            $this->info('已请求打开默认浏览器，请在浏览器中完成授权。');
        } else {
            $this->warn('无法自动打开浏览器，请点击或复制上方链接手动授权。');
        }
    }
}
