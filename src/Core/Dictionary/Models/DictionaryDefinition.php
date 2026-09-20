<?php

declare(strict_types=1);

namespace PTAdmin\Core\Dictionary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 数据字典定义。代码声明字典与后台自定义字典统一投影到该模型。
 *
 * @property int $id
 * @property string $code
 * @property string $title
 * @property string|null $description
 * @property string $source
 * @property string $owner_code
 * @property string $exposure
 * @property string $value_type
 * @property string $extension_policy
 * @property int $version
 * @property bool $status
 */
final class DictionaryDefinition extends Model
{
    use SoftDeletes;

    protected $table = 'admin_dicts';
    protected $guarded = [];
    protected $dateFormat = 'U';
    protected $casts = [
        'version' => 'integer',
        'status' => 'boolean',
    ];
}
