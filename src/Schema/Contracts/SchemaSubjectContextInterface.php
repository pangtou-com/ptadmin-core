<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contracts;

interface SchemaSubjectContextInterface
{
    public function set(mixed $subject): void;

    public function get(): mixed;
}
