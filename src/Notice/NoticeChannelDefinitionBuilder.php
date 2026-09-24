<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

final class NoticeChannelDefinitionBuilder
{
    /** @var array<string, mixed> */
    private array $definition;

    public function __construct(string $type, string $title, string $sourceType, string $sourceCode)
    {
        $type = strtolower(trim($type));
        $label = trim($title);
        $this->definition = [
            'code' => $sourceCode.'.'.$type,
            'type' => $type,
            'title' => $label,
            'editor' => 'content',
            'source_type' => $sourceType,
            'source_code' => $sourceCode,
        ];
    }

    public function code(string $code): self
    {
        $this->definition['code'] = strtolower(trim($code));

        return $this;
    }

    public function editor(string $editor): self
    {
        $this->definition['editor'] = trim($editor);

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->definition;
    }
}
