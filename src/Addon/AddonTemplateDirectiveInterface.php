<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

interface AddonTemplateDirectiveInterface
{
    public function register(AddonTemplateDirectiveRegistry $registry, string $code, array $manifest): void;
}
