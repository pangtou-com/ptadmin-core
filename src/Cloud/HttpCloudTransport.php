<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\ConnectionException;
use PTAdmin\Cloud\Contracts\CloudTransportInterface;

final class HttpCloudTransport implements CloudTransportInterface
{
    public function __construct(private readonly Factory $http)
    {
    }

    public function request(string $method, string $path, array $payload = [], ?string $token = null): array
    {
        try {
            $request = $this->http->acceptJson()->asJson()->connectTimeout(5)->timeout(60)->withoutRedirecting();
            if ($token !== null) {
                $request = $request->withToken($token);
            }
            $response = $request->send($method, CloudProtocol::SITE_URL.'/api/cloud'.$path, [$method === 'GET' ? 'query' : 'json' => $payload]);
        } catch (ConnectionException $exception) {
            throw new CloudException('无法连接云市场平台，请稍后重试。', 'cloud_platform_unavailable');
        }
        $result = $response->json();
        if (!$response->successful() || !is_array($result) || ($result['code'] ?? null) !== 0 || !is_array($result['data'] ?? null)) {
            throw new CloudException(is_string($result['message'] ?? null) ? $result['message'] : '平台响应无效。',
                match ($response->status()) { 401 => 'unauthorized', 403 => 'forbidden', 422 => 'invalid_request', 429 => 'too_many_requests', default => 'cloud_request_failed' });
        }
        return $result['data'];
    }

    public function download(string $token, string $ticket, string $destination, int $maxBytes, string $path = '/licenses/download'): void
    {
        try {
            $response = $this->http->withToken($token)->withHeaders(['X-PTAdmin-Download-Ticket' => $ticket])
                ->connectTimeout(5)->timeout(120)->withoutRedirecting()->withOptions(['sink' => $destination,
                    'progress' => static function ($total, $downloaded) use ($maxBytes): void {
                        if ($downloaded > $maxBytes) {
                            throw new CloudException('下载内容超过版本包声明大小。', 'cloud_response_invalid');
                        }
                    }])->post(CloudProtocol::SITE_URL.'/api/cloud'.$path);
            if (!$response->successful()) {
                throw new CloudException('平台下载失败，请确认授权码或会员授权的版本下载资格。', 'download_failed');
            }
        } catch (ConnectionException $exception) {
            throw new CloudException('下载连接中断，请稍后重试。', 'cloud_platform_unavailable');
        }
    }
}
