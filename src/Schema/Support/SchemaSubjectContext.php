<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Support;

use PTAdmin\Schema\Contracts\SchemaSubjectContextInterface;

final class SchemaSubjectContext implements SchemaSubjectContextInterface
{
    private mixed $subject = null;

    public function set(mixed $subject): void
    {
        $this->subject = $subject;
    }

    public function get(): mixed
    {
        return $this->subject;
    }
}
