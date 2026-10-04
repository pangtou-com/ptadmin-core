<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contracts;

use PTAdmin\Schema\Extension\ExtensionContext;

interface SchemaExtensionValidatorInterface
{
    /** @return list<array{code:string,path:string,message:string}> */
    public function validate(ExtensionContext $context): array;
}
