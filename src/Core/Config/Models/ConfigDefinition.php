<?php

declare(strict_types=1);

namespace PTAdmin\Core\Config\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 配置定义模型。schema_json 描述配置对象和字段规则，不保存具体配置值。
 *
 * @property int $id
 * @property string $group_code
 * @property string $config_key
 * @property string $owner_type
 * @property string $owner_code
 * @property string $description 配置用途说明，未填写时为空字符串。
 * @property string $icon
 * @property string $exposure
 * @property string $activation_mode
 * @property array<string, mixed> $schema_json
 * @property string $checksum
 */
final class ConfigDefinition extends Model
{
    protected $table = 'ptadmin_config_definitions';
    public $timestamps = true;
    protected $guarded = [];
    protected $attributes = ['description' => ''];
    protected $casts = [
        'schema_json' => 'json',
        'status' => 'boolean',
    ];
}
