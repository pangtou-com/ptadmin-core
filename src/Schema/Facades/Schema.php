<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Facades;

use Illuminate\Support\Facades\Facade;
use PTAdmin\Schema\Support\SchemaManager;

/**
 * Schema 的 Laravel Facade 入口。
 *
 * Facade 只负责把静态调用转发给容器中的 `SchemaManager`，不会复制服务逻辑，也不会改变 Go/PHP
 * 协议中的字段名、返回结构和错误码。需要替换宿主适配器或进行单元测试时，优先直接注入对应接口。
 *
 * 常用调用示例：
 *
 * ```php
 * use PTAdmin\Schema\Facades\Schema;
 *
 * // resources() 负责创建和列表；resource() 负责单个资源的草稿、版本和状态。
 * $resources = Schema::resources()->listResources(['page' => 1]);
 * $resource = Schema::resource('demo.product')->getResource();
 *
 * // 查询草稿审批状态。
 * $approval = Schema::publish('demo.product')->getDraftApproval();
 *
 * // Runtime 先绑定资源，后续查询参数不再重复携带 resource。
 * $result = Schema::runtime('demo.product')->list([
 *     'page' => 1,
 *     'limit' => 20,
 * ]);
 *
 * // Tree、记录传输和审计也先绑定资源。
 * $tree = Schema::tree('demo.category')->getTree();
 * $template = Schema::transfers('demo.product')->exportTemplate();
 * $events = Schema::audit('demo.product')->list();
 *
 * // 定义校验和 JSONC 文件加载使用同一个入口。
 * $issues = Schema::definition()->validate($schema);
 * $definition = Schema::loader()->fromFile($path);
 * ```
 *
 * `transfers($resource)` 只返回宿主导入导出适配器边界，不会引入 XLSX 依赖；未注入适配器时仍按协议抛出
 * `action_not_available`。所有带 resource 参数的方法每次返回新的不可变资源作用域，底层服务仍使用 Laravel
 * 容器中的同一个单例。
 *
 * @method static \PTAdmin\Schema\Contracts\ResourceServiceInterface resources() 资源管理对象
 * @method static \PTAdmin\Schema\Meta\Service\ResourceScope resource(string $resource) 单个资源管理
 * @method static \PTAdmin\Schema\Publish\Service\PublishScope publish(string $resource) 发布处理
 * @method static mixed publishTask(int $taskId) 查询发布任务
 * @method static \PTAdmin\Schema\Runtime\RuntimeScope runtime(string $resource) 绑定资源后的运行时
 * @method static \PTAdmin\Schema\Runtime\TreeScope tree(string $resource) 树结构
 * @method static \PTAdmin\Schema\Runtime\RecordTransferScope transfers(string $resource) 记录导入导出
 * @method static \PTAdmin\Schema\Contracts\DefinitionServiceInterface definition() 协议
 * @method static \PTAdmin\Schema\Audit\Service\AuditScope audit(string $resource) 审计
 * @method static \PTAdmin\Schema\SchemaFile\Loader loader() 加载
 */
final class Schema extends Facade
{
    /** 返回容器中负责服务目录的绑定名称。 */
    protected static function getFacadeAccessor(): string
    {
        return SchemaManager::class;
    }
}
