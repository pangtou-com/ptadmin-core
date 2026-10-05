<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Frontend;

use PTAdmin\Addon\Frontend\FrontendPublisher;

/** 应用模块发布边界。实际文件替换沿用已验证的 staging/backup 发布器。 */
final class ApplicationFrontendPublisher
{
    public function __construct(private FrontendPublisher $publisher)
    {
    }

    /** @return array{published:bool,code:string,path:string,files:int} */
    public function publish(string $webPrefix, string $source): array
    {
        return $this->publisher->publishApplication($webPrefix, $source);
    }
}
