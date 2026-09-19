<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Runtime;

final class RuntimeResourceRegistration
{
    public function __construct(
        private RuntimeRegistry $registry,
        private string $resourceKey,
        private array $owner,
    ) {
    }

    public function hook(string $stage, string $service, string $method, int $priority = 0): self
    {
        $this->registry->addHook($this->owner, $this->resourceKey, $stage, $service, $method, $priority);

        return $this;
    }

    public function action(string $actionKey, string $service, string $method): self
    {
        $this->registry->addAction($this->owner, $this->resourceKey, $actionKey, $service, $method);

        return $this;
    }

    public function event(string $eventKey, string $service, string $method, EventMode $mode = EventMode::SyncAfterCommit, int $priority = 0): self
    {
        $this->registry->addEvent($this->owner, $this->resourceKey, $eventKey, $service, $method, $mode, $priority);

        return $this;
    }
}
