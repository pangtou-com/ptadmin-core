<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * VersionInfo 描述资源版本的公开信息。
 */
final class VersionInfo extends ContractObject
{
    /** @var array<string, string|array<class-string>|class-string> */
    protected const FIELD_TYPES = [
        'id' => 'int',
        'version' => 'int',
        'status' => 'string',
        'checksum' => 'string',
        'check_status' => 'string',
        'checked_at' => 'mixed',
        'activated_at' => 'mixed',
        'created_at' => 'mixed',
        'updated_at' => 'mixed',
    ];

    /** @var array<string, array{nullable?: bool, omit_empty?: bool, serialized?: bool}> */
    protected const FIELD_OPTIONS = [
        'check_status' => ['omit_empty' => true],
        'checked_at' => ['nullable' => true, 'omit_empty' => true],
        'activated_at' => ['nullable' => true, 'omit_empty' => true],
    ];

    /** ID 版本内部唯一标识。 */
    public int $id = 0;

    /** Version 版本号。 */
    public int $version = 0;

    /** Status 版本状态。 */
    public string $status = '';

    /** Checksum 版本校验值。 */
    public string $checksum = '';

    /** CheckStatus 最近一次检查状态。 */
    public string $check_status = '';

    /** CheckedAt 最近一次检查完成时间。 */
    public mixed $checked_at = null;

    /** ActivatedAt 版本激活时间。 */
    public mixed $activated_at = null;

    /** CreatedAt 版本创建时间。 */
    public mixed $created_at = null;

    /** UpdatedAt 版本最后更新时间。 */
    public mixed $updated_at = null;
}
