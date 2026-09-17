<?php

declare(strict_types=1);

namespace PTAdmin\Core\Config\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 配置值模型。每个 owner、配置编码和作用域只保存一个 JSON 配置对象。
 * 卸载插件时不删除此表记录，以便重新安装后按稳定 owner 和 key 关联。
 *
 * @property int $id
 * @property string $config_key
 * @property string $owner_type
 * @property string $owner_code
 * @property string $scope_type
 * @property string $scope_id
 * @property array<string, mixed> $value_json
 */
final class ConfigValue extends Model
{
    protected $table = 'ptadmin_config_values';
    public $timestamps = true;
    protected $guarded = [];
    protected $casts = [
        'value_json' => 'json',
    ];
}
