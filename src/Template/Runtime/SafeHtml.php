<?php

declare(strict_types=1);

namespace PTAdmin\Template\Runtime;

/** 插件显式声明可原样输出的 HTML；不替代内容写入端的信任和清洗规则。 */
final class SafeHtml implements \Stringable, \JsonSerializable
{
    public function __construct(private string $html)
    {
    }

    public function __toString(): string
    {
        return $this->html;
    }

    public function jsonSerialize(): string
    {
        return $this->html;
    }
}
