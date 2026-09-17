<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * ExecuteActionResult 描述执行动作结果。
 */
final class ExecuteActionResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'success' => 'bool',
        'message' => 'string',
        'meta' => 'array',
        'item' => 'array',
        'affected' => 'int',
        'payload' => 'array',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'message' => ['omit_empty' => true],
        'meta' => ['omit_empty' => true],
        'item' => ['omit_empty' => true],
        'affected' => ['omit_empty' => true],
        'payload' => ['omit_empty' => true],
    ];

    /** Success 动作执行是否成功。 */
    public bool $success = false;

    /** Message 动作执行结果说明。 */
    public string $message = '';

    /** Meta 附加元信息。 */
    public array $meta = [];

    /** Item 单记录动作可选回填结果。 */
    public array $item = [];

    /** Affected 受影响记录数。 */
    public int $affected = 0;

    /** Payload 动作返回的额外载荷。 */
    public array $payload = [];
}
