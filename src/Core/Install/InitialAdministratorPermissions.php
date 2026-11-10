<?php

declare(strict_types=1);

namespace PTAdmin\Core\Install;

use Illuminate\Database\ConnectionInterface;
use PTAdmin\Admin\Models\Admin;

/** 由安装事务为新建账号保存当时的能力快照；资源同步不得调用此服务。 */
final class InitialAdministratorPermissions
{
    public function __construct(private readonly ConnectionInterface $database)
    {
    }

    public function grant(Admin $admin): void
    {
        $resources = $this->database->table('admin_resources')->whereNull('deleted_at')->where('status', 1)->get();
        $grants = [];
        foreach ($resources as $resource) {
            $meta = json_decode((string) $resource->meta_json, true, 512, JSON_THROW_ON_ERROR);
            $abilities = array_values(array_unique(($meta['abilities'] ?? [])));
            $abilities = array_values(array_filter($abilities, static fn (string $ability): bool => $ability !== '*'));
            $grants[] = [
                'subject_type' => 'admin', 'subject_id' => (string) $admin->getKey(),
                'resource_id' => (int) $resource->id, 'effect' => 'allow',
                'abilities_json' => json_encode($abilities, JSON_THROW_ON_ERROR), 'priority' => 0,
                'scope_type' => 'all', 'scope_value_json' => null, 'conditions_json' => null, 'expires_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        if ($grants !== []) {
            $this->database->table('admin_grants')->insert($grants);
        }
    }
}
