<?php

declare(strict_types=1);

namespace PTAdmin\User\Contracts;

use Illuminate\Database\Query\Builder;

/** 跨插件复用的前台用户身份契约；宿主可替换会话、模型和验证规则。 */
interface UserIdentityInterface
{
    /** @return array{id:int,name:string,active:bool,phone_verified:bool,realname_verified:bool}|null */
    public function find(int $id): ?array;

    public function currentId(): ?int;

    /** 仅返回有效用户的公开昵称和标识，不返回联系方式或验证资料。
     * @return list<array{id:int,name:string}>
     */
    public function search(string $name): array;

    /** 批量读取历史用户昵称，包含停用账号；只返回公开标识，不返回联系方式。
     * @param list<int> $ids
     * @return list<array{id:int,name:string}>
     */
    public function profiles(array $ids, string $keyword = '', ?int $limit = null): array;

    /** 对业务查询叠加有效作者条件，避免分页和数量暴露停用作者内容。 */
    public function constrainActive(Builder $query, string $authorColumn): void;
}
