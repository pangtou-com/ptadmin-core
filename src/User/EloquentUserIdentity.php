<?php

declare(strict_types=1);

namespace PTAdmin\User;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use PTAdmin\User\Contracts\UserIdentityInterface;

final class EloquentUserIdentity implements UserIdentityInterface
{
    /** 解析默认用户模型；只校验配置和类型，不访问用户数据库。 */
    public function model(): string
    {
        $provider = config('auth.guards.web.provider');
        $model = is_string($provider) ? config('auth.providers.'.$provider.'.model') : null;
        if (!is_string($model) || !is_subclass_of($model, Model::class)
            || config('auth.providers.'.$provider.'.driver') !== 'eloquent') {
            throw new InvalidArgumentException('用户身份配置无效：请在 config/auth.php 中为 guards.web 对应的 provider 配置 Eloquent 用户模型，或绑定 UserIdentityInterface。');
        }

        return $model;
    }

    public function currentId(): ?int
    {
        // 明确使用前台 web 身份，不跟随后台请求可能切换的默认 guard。
        $id = Auth::guard('web')->id();
        return $id === null ? null : (int) $id;
    }

    public function find(int $id): ?array
    {
        $user = ($this->model())::query()->find($id);
        if ($user === null) {
            return null;
        }
        return ['id' => (int) $user->getKey(), 'name' => (string) $user->name,
            'active' => (bool) $user->status, 'phone_verified' => $user->phone_verified_at !== null,
            'realname_verified' => $user->realname_verified_at !== null];
    }

    public function constrainActive(Builder $query, string $authorColumn): void
    {
        $model = $this->model();
        $query->whereIn($authorColumn, (new $model())->newQuery()->where('status', true)->select((new $model())->getKeyName()));
    }

    public function search(string $name): array
    {
        return ($this->model())::query()->where('status', true)->where('name', 'like', '%'.$name.'%')
            ->orderBy('id')->limit(10)->get()->map(static fn ($user) => ['id' => (int) $user->getKey(), 'name' => (string) $user->name])->all();
    }

    public function profiles(array $ids, string $keyword = '', ?int $limit = null): array
    {
        if ($ids === []) {
            return [];
        }

        $model = new ($this->model())();
        $query = $model->newQuery()->whereIn($model->getKeyName(), $ids);
        if ($keyword !== '') {
            $query->where('name', 'like', '%'.$keyword.'%');
        }
        if ($limit !== null) {
            $query->limit($limit);
        }
        return $query->orderBy($model->getKeyName())->get([$model->getKeyName(), 'name'])
            ->map(static fn ($user) => ['id' => (int) $user->getKey(), 'name' => (string) $user->name])->all();
    }
}
