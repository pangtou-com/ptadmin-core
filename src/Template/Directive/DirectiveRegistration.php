<?php

declare(strict_types=1);

namespace PTAdmin\Template\Directive;

use RuntimeException;

final class DirectiveRegistration
{
    /** @var list<DirectiveDefinition> */
    private array $definitions = [];

    private bool $committed = false;

    public function __construct(
        private readonly DirectiveRegistry $registry,
        private readonly string            $namespace,
    ) {}

    public function define(string $name): DirectiveBuilder
    {
        $this->assertPending();

        return new DirectiveBuilder($this, $name);
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function add(DirectiveDefinition $definition): void
    {
        $this->assertPending();
        if ($definition->namespace() !== $this->namespace) {
            throw new RuntimeException('Template directive registration namespace mismatch.');
        }

        $this->definitions[] = $definition;
    }

    public function commit(): void
    {
        $this->assertPending();
        $this->registry->commitBatch($this->definitions);
        $this->committed = true;
    }

    private function assertPending(): void
    {
        if ($this->committed) {
            throw new RuntimeException('Template directive registration is already committed.');
        }
    }
}
