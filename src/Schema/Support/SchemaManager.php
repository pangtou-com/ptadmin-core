<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Support;

use Illuminate\Contracts\Container\Container;
use PTAdmin\Schema\Audit\Service\AuditScope;
use PTAdmin\Schema\Audit\Service\AuditWriter;
use PTAdmin\Schema\Contracts\DefinitionServiceInterface;
use PTAdmin\Schema\Contracts\PublishServiceInterface;
use PTAdmin\Schema\Contracts\RecordTransferServiceInterface;
use PTAdmin\Schema\Contracts\ResourceServiceInterface;
use PTAdmin\Schema\Contracts\RuntimeEngineInterface;
use PTAdmin\Schema\Contracts\TreeServiceInterface;
use PTAdmin\Schema\Meta\Service\ResourceScope;
use PTAdmin\Schema\Publish\Service\PublishScope;
use PTAdmin\Schema\Runtime\RecordTransferScope;
use PTAdmin\Schema\Runtime\RuntimeScope;
use PTAdmin\Schema\Runtime\TreeScope;
use PTAdmin\Schema\SchemaFile\Loader;

/**
 * Laravel Facade 使用的服务目录。
 *
 * 目录只负责从容器取得已注册服务，不在此处增加业务逻辑，保证 Facade 调用与依赖注入调用得到同一个单例。
 */
final class SchemaManager
{
    public function __construct(private Container $container)
    {
    }

    /** 返回资源、草稿和版本生命周期服务。 */
    public function resources(): ResourceServiceInterface
    {
        return $this->container->make(ResourceServiceInterface::class);
    }

    /** 返回绑定指定资源的元数据、草稿、版本和状态作用域。 */
    public function resource(string $resource): ResourceScope
    {
        return new ResourceScope(
            $resource,
            $this->container->make(ResourceServiceInterface::class)
        );
    }

    /** 返回绑定指定资源的草稿校验、迁移和发布作用域。 */
    public function publish(string $resource): PublishScope
    {
        return new PublishScope(
            $resource,
            $this->container->make(PublishServiceInterface::class)
        );
    }

    /** 按任务主键查询发布任务；任务本身携带所属资源信息。 */
    public function publishTask(int $taskId): mixed
    {
        return $this->container->make(PublishServiceInterface::class)->getPublishTask($taskId);
    }

    /** 返回绑定指定资源的 Runtime CRUD、动作和关联候选作用域。 */
    public function runtime(string $resource): RuntimeScope
    {
        return new RuntimeScope(
            $resource,
            $this->container->make(RuntimeEngineInterface::class)
        );
    }

    /** 返回绑定指定资源的树查询、移动和重建作用域。 */
    public function tree(string $resource): TreeScope
    {
        return new TreeScope(
            $resource,
            $this->container->make(TreeServiceInterface::class)
        );
    }

    /** 返回绑定指定资源的宿主导入导出适配器作用域。 */
    public function transfers(string $resource): RecordTransferScope
    {
        return new RecordTransferScope(
            $resource,
            $this->container->make(RecordTransferServiceInterface::class)
        );
    }

    /** 返回 Schema 归一化、校验和 checksum 服务。 */
    public function definition(): DefinitionServiceInterface
    {
        return $this->container->make(DefinitionServiceInterface::class);
    }

    /** 返回绑定指定资源的审计写入和查询作用域。 */
    public function audit(string $resource): AuditScope
    {
        return new AuditScope(
            $resource,
            $this->container->make(AuditWriter::class)
        );
    }

    /** 返回 JSONC Schema 文件加载器。 */
    public function loader(): Loader
    {
        return $this->container->make(Loader::class);
    }
}
