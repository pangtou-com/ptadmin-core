<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Runtime\Contracts;

use PTAdmin\Template\Directive\DirectiveRegistration;

/** 插件向通用 Template Registry 注册前台模板指令的固定入口。 */
interface TemplateDirectiveProviderInterface
{
    /**
     * 在当前插件的临时注册事务中声明指令；全部文件成功后才会合并到全局 Registry。
     *
     * @param array<string, mixed> $manifest 已完成 manifest 与文件清单验证的数据
     */
    public function register(DirectiveRegistration $registration, array $manifest): void;
}
