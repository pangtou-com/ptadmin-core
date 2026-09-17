<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Definition;

/**
 * DefinitionService 的默认实现，统一保证调用方得到 Schema 对象和稳定 checksum。
 */
final class DefinitionService implements \PTAdmin\Schema\Contracts\DefinitionServiceInterface
{
    /** @param array<string, mixed>|Schema $schema */
    public function normalize(array|Schema $schema): Schema
    {
        return $schema instanceof Schema ? $schema : new Schema($schema);
    }
    /** @param array<string, mixed>|Schema $schema @return list<array{code:string,path:string,message:string}> */
    public function validate(array|Schema $schema): array
    {
        return $this->normalize($schema)->validate();
    }
    /** @param array<string, mixed>|Schema $schema */
    public function checksum(array|Schema $schema): string
    {
        return $this->normalize($schema)->checksum();
    }
}
