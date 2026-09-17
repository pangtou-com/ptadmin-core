<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ValidationResult 描述一次检查的总体结果。
 */
final class ValidationResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'passed' => 'bool',
        'errors' => [ValidationIssue::class],
        'warnings' => [ValidationIssue::class],
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'errors' => ['omit_empty' => true],
        'warnings' => ['omit_empty' => true],
    ];

    /** Passed 本次检查是否通过。 */
    public bool $passed = false;

    /** Errors 阻断发布的错误列表。 */
    public array $errors = [];

    /** Warnings 不阻断发布的告警列表。 */
    public array $warnings = [];
}
