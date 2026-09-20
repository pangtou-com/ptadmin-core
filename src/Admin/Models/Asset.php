<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 公开资源记录。私有上传文件不进入该模型，其生命周期由调用业务自行管理。
 *
 * @property int $id
 * @property string $title
 * @property string $original_title
 * @property string $mime
 * @property string $suffix
 * @property int $size
 * @property string $disk_code
 * @property string $bucket
 * @property string $path
 * @property string $group_code
 * @property int $ref_count
 * @property string $sha256
 * @property array<string, mixed>|null $metadata_json
 */
final class Asset extends Model
{
    use SoftDeletes;

    protected $table = 'assets';

    protected $guarded = [];

    protected $dateFormat = 'U';

    protected $casts = [
        'size' => 'integer',
        'ref_count' => 'integer',
        'metadata_json' => 'array',
    ];
}
