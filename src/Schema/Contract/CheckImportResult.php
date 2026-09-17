<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * CheckImportResult 描述导入检查结果。
 */
final class CheckImportResult extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'session' => ImportSession::class,
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
    ];

    /** Session 导入检查后返回的完整会话状态。 */
    public ?ImportSession $session = null;
}
