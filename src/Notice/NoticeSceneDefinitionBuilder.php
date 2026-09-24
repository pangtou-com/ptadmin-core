<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

final class NoticeSceneDefinitionBuilder
{
    /** @var array<string, mixed> */
    private array $definition;

    public function __construct(string $code, string $title, string $sourceType, string $sourceCode)
    {
        $this->definition = [
            'code' => trim($code),
            'title' => trim($title),
            'description' => '',
            'purpose' => 'transactional',
            'parameters' => [],
            'channels' => [],
            'supports' => [],
            'record_in_app' => false,
            'source_type' => $sourceType,
            'source_code' => $sourceCode,
        ];
    }

    public function description(string $description): self
    {
        $this->definition['description'] = trim($description);

        return $this;
    }

    public function purpose(string $purpose): self
    {
        $this->definition['purpose'] = trim($purpose);

        return $this;
    }

    /** @param array<int, array<string, mixed>> $parameters */
    public function parameters(array $parameters): self
    {
        $this->definition['parameters'] = $parameters;

        return $this;
    }

    /** @param array<int, string> $channels */
    public function channels(array $channels): self
    {
        $this->definition['channels'] = array_values(array_unique(array_map(static fn (string $value): string => trim($value), $channels)));

        return $this;
    }

    /** @param array<int, string> $supports */
    public function supports(array $supports): self
    {
        $this->definition['supports'] = array_values(array_unique(array_map(static fn (string $value): string => trim($value), $supports)));

        return $this;
    }

    public function recordInApp(bool $enabled = true): self
    {
        $this->definition['record_in_app'] = $enabled;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->definition;
    }
}
