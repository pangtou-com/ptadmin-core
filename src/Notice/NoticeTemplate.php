<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

final class NoticeTemplate
{
    /**
     * @param array<mixed> $parameters
     * @param array<mixed>|null $action
     */
    public static function resolve(
        string $scene,
        string $channel,
        string $locale = 'zh-CN',
        array $parameters = [],
        ?array $action = null,
    ): ResolvedNoticeTemplate {
        return app(NoticeTemplateResolver::class)->resolve($scene, $channel, $locale, $parameters, $action);
    }
}
