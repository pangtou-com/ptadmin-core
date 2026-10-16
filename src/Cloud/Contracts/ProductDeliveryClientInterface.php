<?php

declare(strict_types=1);

namespace PTAdmin\Cloud\Contracts;

/** 项目授权和私有包交付；下载凭证只在服务端短暂使用。 */
interface ProductDeliveryClientInterface
{
    public function redeemLicenseCode(string $accessToken, array $payload): array;
    public function deliveryOptions(string $accessToken, array $payload): array;
    public function prepareDelivery(string $accessToken, array $payload): array;
    public function project(string $accessToken, array $payload): array;
    public function bindLicense(string $accessToken, array $payload): array;
    public function releaseLicense(string $accessToken, array $payload): array;
    /** 下载到任务私有目录，并验证平台返回的摘要与字节数。 */
    public function downloadPackage(string $accessToken, array $payload, string $destination): array;
}
