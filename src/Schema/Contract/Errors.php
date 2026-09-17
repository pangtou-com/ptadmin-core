<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

/**
 * Schema 对外稳定错误码，服务层不得用 PHP 专属错误替代这些代码。
 */
final class Errors
{
    public const RESOURCE_KEY_CONFLICT = 'resource_key_conflict';
    public const SOURCE_TYPE_CONFLICT = 'source_type_conflict';
    public const IMMUTABLE_FIELD_VIOLATION = 'immutable_field_violation';
    public const RESOURCE_ARCHIVED = 'resource_archived';
    public const DRAFT_NOT_FOUND = 'draft_not_found';
    public const ACTIVE_VERSION_NOT_FOUND = 'active_version_not_found';
    public const VERSION_NOT_FOUND = 'version_not_found';
    public const CHECKSUM_MISMATCH = 'checksum_mismatch';
    public const PUBLISH_IN_PROGRESS = 'publish_in_progress';
    public const CHECK_REQUIRED = 'check_required';
    public const CHECK_CHECKSUM_MISMATCH = 'check_checksum_mismatch';
    public const DESTRUCTIVE_APPROVAL_REQUIRED = 'destructive_approval_required';
    public const VERSION_ALREADY_PUBLISHED = 'version_already_published';
    public const PUBLISH_TASK_NOT_FOUND = 'publish_task_not_found';
    public const MIGRATION_FAILED = 'migration_failed';
    public const ACTIVATION_FAILED = 'activation_failed';
    public const PROJECTION_FAILED = 'projection_failed';
    public const RESOURCE_DISABLED = 'resource_disabled';
    public const RECORD_NOT_FOUND = 'record_not_found';
    public const ACTION_NOT_FOUND = 'action_not_found';
    public const ACTION_NOT_AVAILABLE = 'action_not_available';
    public const SCOPE_DENIED = 'scope_denied';
    public const INVALID_INPUT = 'invalid_input';
    public const UNKNOWN_FIELD = 'unknown_field';
    public const MANAGED_FIELD = 'managed_field';
    public const REVISION_CONFLICT = 'revision_conflict';
    public const PERMISSION_DENIED = 'permission_denied';
}
