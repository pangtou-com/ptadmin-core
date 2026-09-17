<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ValidationIssue 描述一条检查问题。
 */
final class ValidationIssue extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'code' => 'string',
        'path' => 'string',
        'message' => 'string',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Code 问题代码。 */
    public string $code = '';

    /** Path 问题定位路径。 */
    public string $path = '';

    /** Message 问题说明。 */
    public string $message = '';
}
