<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Runtime 记录集合，提供统一的首条记录、遍历和协议序列化入口。
 */
final class Records implements Countable, IteratorAggregate, JsonSerializable
{
    /** @var list<Record> */
    private array $records;

    /** @param list<Record|array<string, mixed>> $records */
    public function __construct(array $records = [])
    {
        $this->records = array_map(
            static fn ($record): Record => $record instanceof Record ? $record : new Record($record),
            $records,
        );
    }

    public function count(): int
    {
        return count($this->records);
    }

    public function first(): ?Record
    {
        return $this->records[0] ?? null;
    }

    public function getIterator(): Traversable
    {
        yield from $this->records;
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (Record $record): array => $record->toArray(), $this->records);
    }

    /** @return list<array<string, mixed>> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
