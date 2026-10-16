<?php

declare(strict_types=1);

namespace PTAdmin\Cloud\Contracts;

/** 商品目录读取边界；平台宿主可直接提供本地目录，独立应用使用 HTTP。 */
interface CloudCatalogInterface
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function catalog(array $filters = []): array;
}
