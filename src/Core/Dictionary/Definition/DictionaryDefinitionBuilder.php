<?php

declare(strict_types=1);

namespace PTAdmin\Core\Dictionary\Definition;

/** 单个字典的可读链式声明构造器。 */
final class DictionaryDefinitionBuilder
{
    /** @var array<string, mixed> */
    private array $definition;

    public function __construct(string $code, string $title, string $source, string $ownerCode)
    {
        $this->definition = [
            'code' => trim($code),
            'title' => trim($title),
            'description' => '',
            'source' => $source,
            'owner_code' => $ownerCode,
            'exposure' => 'internal',
            'value_type' => 'string',
            'extension_policy' => 'closed',
            'entries' => [],
        ];
    }

    public function description(string $description): self
    {
        $this->definition['description'] = trim($description);

        return $this;
    }

    public function desc(string $description): self
    {
        return $this->description($description);
    }

    public function exposure(string $exposure): self
    {
        $this->definition['exposure'] = $exposure;

        return $this;
    }

    public function valueType(string $valueType): self
    {
        $this->definition['value_type'] = $valueType;

        return $this;
    }

    public function closed(): self
    {
        $this->definition['extension_policy'] = 'closed';

        return $this;
    }

    public function appendable(): self
    {
        $this->definition['extension_policy'] = 'append';

        return $this;
    }

    /** @param array<string, mixed> $display */
    public function addItem(string|int|float $value, string $label, array $display = [], bool $enabled = true, int $sort = 0): self
    {
        $this->definition['entries'][] = [
            'label' => $label,
            'value' => $value,
            'display' => $display === [] ? ['type' => 'default'] : $display,
            'enabled' => $enabled,
            'sort' => $sort,
        ];

        return $this;
    }

    /** @param array<string, mixed> $item */
    public function item(array $item): self
    {
        $this->definition['entries'][] = $item;

        return $this;
    }

    /** @param array<int, array<string, mixed>> $items */
    public function items(array $items): self
    {
        foreach ($items as $item) {
            $this->item($item);
        }

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->definition;
    }
}
