<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contracts;

/**
 * 运行时 CRUD、动作和关联候选查询边界。实现必须返回协议约定的数组结构，并以 SchemaError 表达业务失败。
 */
interface RuntimeEngineInterface
{
    /** 分页查询资源记录。 */
    public function list(array|object $query): array;
    /** 查询全部记录，max 用于限制大结果集。 */
    public function getAll(array|object $query, ?int $max = null): array;
    /** 返回查询结果第一条记录，没有记录时 item 为空数组。 */
    public function first(array|object $query): array;
    /** 按主键查询一条记录，并应用回收站和服务端约束。 */
    public function detail(array|object $query): array;
    /** 按资源编码和主键查询记录。 */
    public function find(string $resourceKey, int $id): array;
    /** 创建记录并执行默认值、生成器和字段校验。 */
    public function create(array|object $input): array;
    /** 更新记录；只写入输入中经过协议处理的字段。 */
    public function update(array|object $input): array;
    /** 删除记录，按资源能力选择软删除或物理删除。 */
    public function delete(array|object $input): array;
    /** 恢复回收站记录。 */
    public function restore(array|object $input): array;
    /** 永久删除回收站记录，调用方应自行承担不可恢复风险。 */
    public function purge(array|object $input): array;
    /** 执行内置或宿主注入动作，并校验动作能力。 */
    public function executeAction(array|object $input): array;
    /** 查询关系字段的静态、字典或关联候选项。 */
    public function relationCandidates(array|object $query): array;
    /** 返回指定版本的 Schema 快照及校验信息。 */
    public function schemaSnapshot(array|object $query): array;
}
