<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

interface AddonTemplateDirectiveInterface
{
    public function register(AddonTemplateDirectiveRegistration $registration, array $manifest): void;
}
