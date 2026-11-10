<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Lifecycle;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** 安装确认授权与增量补权；普通资源同步、启停和 Setup 不调用此服务。 */
final class AddonUsagePermissions
{
    public function __construct(private readonly ConnectionInterface $database, private readonly LifecycleStore $lifecycle)
    {
    }

    /** @return array{founder:bool,role_ids:list<int>} */
    public function selection(string $code, mixed $input = null): array
    {
        $code = strtolower($code);
        $input ??= $this->lifecycle->read($code)['usage_permissions']['selection'] ?? ['founder' => true, 'role_ids' => []];
        $validated = Validator::make(['usage_permissions' => $input], [
            'usage_permissions' => ['required', 'array:founder,role_ids'],
            'usage_permissions.founder' => ['required', 'boolean'],
            'usage_permissions.role_ids' => ['present', 'array', 'max:100'],
            'usage_permissions.role_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ])->validate()['usage_permissions'];
        $selection = ['founder' => (bool) $validated['founder'], 'role_ids' => array_map('intval', $validated['role_ids'])];
        sort($selection['role_ids']);
        $this->subjects($selection);
        return $selection;
    }

    /** 提供本地账号角色选项，不把业务授权资料发给云平台。 */
    public function options(string $code): array
    {
        $code = strtolower($code);
        $selection = $this->lifecycle->read($code)['usage_permissions']['selection'] ?? ['founder' => true, 'role_ids' => []];
        $roles = $this->database->table('admin_roles')->whereNull('deleted_at')->orderBy('sort')->orderBy('id')
            ->get(['id', 'name', 'status'])->map(static fn (object $role): array => [
                'id' => (int) $role->id, 'name' => $role->name, 'available' => (bool) $role->status,
            ])->all();
        $knownIds = array_column($roles, 'id');
        foreach (array_diff($selection['role_ids'], $knownIds) as $id) {
            $roles[] = ['id' => $id, 'name' => '角色 #'.$id.'（已删除）', 'available' => false];
        }
        return ['selection' => $selection, 'roles' => $roles];
    }

    /**
     * 在安装器的激活事务内追加授权，并返回随生命周期快照保存的处理记录。
     * 已处理能力即使被人工取消也不补回；已有拒绝、范围和条件记录保持原样。
     *
     * @param array{founder:bool,role_ids:list<int>} $selection
     * @param array<string, mixed> $previous
     * @return array<string, mixed>
     */
    public function apply(string $code, array $selection, array $previous): array
    {
        $subjects = $this->subjects($selection);
        $resources = $this->database->table('admin_resources')->whereNull('deleted_at')->where('status', 1)
            ->where('module', 'addon')->get(['id', 'resource_key', 'meta_json'])->filter(static function (object $resource) use ($code): bool {
                $meta = json_decode($resource->meta_json, true, 512, JSON_THROW_ON_ERROR);
                return ($meta['owner_type'] ?? null) === 'addon' && ($meta['owner_code'] ?? null) === $code;
            });
        $existing = $this->database->table('admin_grants')->whereIn('resource_id', $resources->pluck('id'))
            ->whereIn('subject_id', array_column($subjects, 'id'))->get(['subject_type', 'subject_id', 'resource_id', 'abilities_json']);
        $configured = [];
        foreach ($existing as $grant) {
            $key = $grant->subject_type.':'.$grant->subject_id;
            $configured[$key][$grant->resource_id] = array_merge($configured[$key][$grant->resource_id] ?? [],
                json_decode($grant->abilities_json, true, 512, JSON_THROW_ON_ERROR));
        }
        $history = $previous['history'] ?? [];
        $rows = [];
        foreach ($subjects as $subject) {
            $key = $subject['type'].':'.$subject['id'];
            foreach ($resources as $resource) {
                $meta = json_decode($resource->meta_json, true, 512, JSON_THROW_ON_ERROR);
                $abilities = array_values(array_diff(array_unique(($meta['abilities'] ?? [])), ['*']));
                $seen = $history[$key][$resource->resource_key] ?? [];
                $configuredAbilities = $configured[$key][$resource->id] ?? [];
                $missing = in_array('*', $configuredAbilities, true) ? [] : array_values(array_diff($abilities, $seen, $configuredAbilities));
                $history[$key][$resource->resource_key] = array_values(array_unique(array_merge($seen, $abilities)));
                if ($missing === []) {
                    continue;
                }
                $rows[] = [
                    'subject_type' => $subject['type'], 'subject_id' => $subject['id'], 'resource_id' => $resource->id,
                    'effect' => 'allow', 'abilities_json' => json_encode($missing, JSON_THROW_ON_ERROR), 'priority' => 0,
                    'scope_type' => 'all', 'scope_value_json' => null, 'conditions_json' => null, 'expires_at' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        if ($rows !== []) {
            $this->database->table('admin_grants')->insert($rows);
        }
        return ['selection' => $selection, 'history' => $history];
    }

    /** @param array{founder:bool,role_ids:list<int>} $selection @return list<array{type:string,id:string}> */
    private function subjects(array $selection): array
    {
        $subjects = [];
        if ($selection['founder']) {
            $founder = $this->database->table('admins')->whereNull('deleted_at')->where('status', 1)->where('is_founder', 1)->orderBy('id')->value('id');
            if ($founder === null) {
                throw ValidationException::withMessages(['usage_permissions.founder' => '创始人账号不可用，请重新确认插件使用权限。']);
            }
            $subjects[] = ['type' => 'admin', 'id' => (string) $founder];
        }
        $roleIds = $this->database->table('admin_roles')->whereNull('deleted_at')->where('status', 1)
            ->whereIn('id', $selection['role_ids'])->pluck('id')->all();
        if (array_diff($selection['role_ids'], $roleIds) !== []) {
            throw ValidationException::withMessages(['usage_permissions.role_ids' => '所选角色已停用或删除，请重新确认插件使用权限。']);
        }
        foreach ($selection['role_ids'] as $id) {
            $subjects[] = ['type' => 'role', 'id' => (string) $id];
        }
        return $subjects;
    }
}
