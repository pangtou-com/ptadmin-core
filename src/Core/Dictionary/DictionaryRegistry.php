<?php

declare(strict_types=1);

namespace PTAdmin\Core\Dictionary;

use PTAdmin\Core\Dictionary\Definition\DictionaryDefinitionBuilder;

/** 收集代码声明并绑定可信的 source/owner，上层同步器只接收其规范化结果。 */
final class DictionaryRegistry
{
    /** @var array<int, DictionaryDefinitionBuilder> */
    private array $definitions = [];

    public function __construct(
        private readonly string $source,
        private readonly string $ownerCode,
    ) {
        if (!in_array($source, ['system', 'plugin'], true) || $ownerCode === '') {
            throw new DictionaryException('字典声明 owner 无效。', 'dictionary_owner_invalid');
        }
    }

    public function define(string $code, string $title): DictionaryDefinitionBuilder
    {
        $builder = new DictionaryDefinitionBuilder($code, $title, $this->source, $this->ownerCode);
        $this->definitions[] = $builder;

        return $builder;
    }

    /** @return array<int, array<string, mixed>> */
    public function definitions(): array
    {
        return array_map(static fn (DictionaryDefinitionBuilder $definition): array => $definition->toArray(), $this->definitions);
    }
}
