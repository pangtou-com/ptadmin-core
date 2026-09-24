<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

final class NoticeScene
{
    public static function define(string $code, array|string $definition): NoticeSceneDefinitionBuilder
    {
        $title = is_array($definition) ? (string) ($definition['title'] ?? '') : $definition;
        if ($title === '') {
            throw new NoticeDefinitionException('通知场景名称不能为空。');
        }
        $builder = app(NoticeRegistry::class)->scene($code, $title);
        if (!is_array($definition)) {
            return $builder;
        }
        if (isset($definition['description'])) {
            $builder->description((string) $definition['description']);
        }
        if (isset($definition['purpose'])) {
            $builder->purpose((string) $definition['purpose']);
        }
        if (isset($definition['parameters'])) {
            $builder->parameters((array) $definition['parameters']);
        }
        if (isset($definition['channels'])) {
            $builder->channels((array) $definition['channels']);
        }
        if (isset($definition['supports'])) {
            $builder->supports((array) $definition['supports']);
        }
        if (isset($definition['record_in_app'])) {
            $builder->recordInApp((bool) $definition['record_in_app']);
        }

        return $builder;
    }
}
