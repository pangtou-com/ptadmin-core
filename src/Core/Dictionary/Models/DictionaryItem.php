<?php

declare(strict_types=1);

namespace PTAdmin\Core\Dictionary\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 数据字典条目。value 始终以字符串存储，由服务按字典值类型序列化。
 *
 * @property int $id
 * @property int $dict_id
 * @property string $label
 * @property string $value
 * @property array<string, mixed>|null $display_json
 * @property string $source
 * @property string $owner_code
 * @property bool $enabled
 * @property int $sort
 */
final class DictionaryItem extends Model
{
    use SoftDeletes;

    protected $table = 'admin_dict_items';
    protected $guarded = [];
    protected $dateFormat = 'U';
    protected $casts = [
        'display_json' => 'array',
        'enabled' => 'boolean',
        'sort' => 'integer',
    ];
}
