<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use RuntimeException;

final class AddonTemplateDirectiveRegistration
{
    /** @var list<TemplateDirectiveDefinition> */
    private array $definitions = [];

    private bool $committed = false;

    public function __construct(
        private AddonTemplateDirectiveRegistry $registry,
        private string $addonCode,
    ) {}

    public function define(string $name): TemplateDirectiveBuilder
    {
        $this->assertPending();

        return new TemplateDirectiveBuilder($this, $name);
    }

    public function addonCode(): string
    {
        return $this->addonCode;
    }

    public function add(TemplateDirectiveDefinition $definition): void
    {
        $this->assertPending();
        if ($definition->addonCode() !== $this->addonCode) {
            throw new RuntimeException('Template directive registration addon mismatch.');
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
