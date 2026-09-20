# Schema Runtime 扩展

## 适用范围

Schema Runtime 扩展用于给代码资源注册可信的 Hook、Action 和 Resource Event Listener。它适用于 Core、宿主应用和已验证插件，不用于把 PHP 类名或方法名写入 Schema JSON，也不替代通用 CRUD、认证和授权。

普通资源不需要注册扩展。没有 Hook、Action 或 Event 绑定的资源继续使用同一个通用 `RuntimeEngine`，请求期间不会扫描 Schema 文件或插件目录。

## Owner 和注册入口

Runtime Owner 由可信加载入口固定：

- Core：`core/ptadmin`
- 宿主应用：`app/host`
- 插件：`addon/{manifest.code}`

贡献方不能自行覆盖 Owner。宿主通过 `RuntimeApplicationRegistrar` 注册：

```php
use PTAdmin\Schema\Runtime\RuntimeApplicationRegistrar;

$app->make(RuntimeApplicationRegistrar::class)->register(
    static function ($runtime): void {
        $runtime->resource('cms.articles')
            ->hook('create.prepare', ArticleHooks::class, 'prepareCreate', priority: 100)
            ->action('publish', ArticleActions::class, 'publish')
            ->event('published', ArticleListeners::class, 'published');
    }
);
```

插件在 `manifest.json` 中声明 Runtime 入口，注册类必须实现 `RuntimeRegistrarInterface`：

```json
{
  "runtime": {
    "file": "Runtime/Registrar.php",
    "class": "Vendor\\Addon\\Runtime\\Registrar"
  }
}
```

插件只有在 manifest、签名、规范化文件清单和生命周期状态验证通过后才能贡献绑定。插件注册使用经过验证的 manifest code，不能以目录名伪造 Owner。

## Hook

Hook 在当前 CRUD 事务内同步执行。Hook 抛出异常会终止后续处理器并回滚主写入。

支持的阶段：

- `create.prepare`、`create.before`、`create.after`
- `update.prepare`、`update.before`、`update.after`
- `delete.before`、`delete.after`
- `restore.before`、`restore.after`
- `purge.before`、`purge.after`
- `output.transform`

同一阶段按 `priority` 降序执行，相同优先级按稳定注册序号执行。处理器由 Laravel 容器在对应阶段真正执行时解析，不在构建索引时实例化。

## Action

Schema 只声明 Action key、启用状态和展示信息；PHP 注册表提供唯一可信执行器。一个 `resource_key + action_key` 只能有一个绑定。

Action 固定经过以下链路：

1. 解析 active Schema。
2. 检查 Action 已声明并启用。
3. 使用 Admin Transport 完成认证、授权和请求审计。
4. 从 Runtime 索引解析唯一绑定。
5. 由容器按需解析处理器并执行。

`list`、`view`、`create`、`update`、`delete`、`restore`、`purge` 是通用 CRUD 保留键，不能注册为自定义 Action。Schema 声明但没有实现时返回 `action_binding_missing`；Runtime 注册但 Schema 未声明时报告 `action_not_declared`，不会回退到任意通用处理器。

前端通过公共 `DataAdapter.executeAction()` 调用统一入口：

```json
{
  "operation": "executeAction",
  "context": {
    "resource": "cms.articles",
    "action": "publish",
    "id": 42,
    "selection": [{ "id": 42 }],
    "formValues": { "source": "editor" }
  }
}
```

请求地址来自 Bootstrap 配置的 Admin API 基础路径，默认统一入口为 `POST /ptadmin/schema/runtime`。请求使用当前 Bearer Token；响应继续使用 `code / message / data`，请求标识只从 `X-Request-ID` 响应头读取。

## Resource Event

Resource Event 表示事务已经提交的业务事实，不等同于 `after` Hook。标准 CRUD 事件包括：

- `created`
- `updated`
- `deleted`
- `restored`
- `purged`

Action 可以通过 `RuntimeHookContext::recordEvent()` 记录领域事件。事件只在主事务成功提交后分发；同步 Listener 或队列 Listener 的异常不会回滚已经提交的数据，也不会把成功的 Action 响应改为失败。

Event 载荷必须能够 JSON 序列化，不能包含 Request、Closure、数据库连接或 Eloquent Model。Runtime 使用事件 ID、幂等键和请求内递归保护阻止同一事件重复进入。

Resource Event 是服务端 Listener 协议，第一版不通过 Admin API、SSE 或浏览器事件总线暴露给前端。前端只消费 CRUD/Action 的响应结果；需要用户可见的异步进度时使用文件任务状态机和 JSONL Task Event，不把 Resource Event 当作任务事件。

## 校验和缓存

`RuntimeRegistry::build()` 执行绑定格式、Owner、阶段、服务方法、Action 唯一性和保留键校验，并生成不可变快照。描述符的 `sources` 按实际贡献保存独立 SHA-256：

```php
[
    'core/ptadmin' => '...',
    'app/host' => '...',
    'addon/cms' => '...',
]
```

`RuntimeRegistryValidator` 再把快照与数据库中的 active Schema 关联校验。Schema 文件和 Admin 同步只校验本次资源；draft-only 不校验未发布定义；`runtime:validate` 和 `runtime:cache` 执行全量校验。

Runtime 缓存位于 `bootstrap/cache/ptadmin-runtime.php`。缓存只保存标量和数组描述符，不保存服务对象或 Closure。缓存缺失、格式错误、来源 checksum 非法或整体 checksum 不匹配时，运行时回退到当前进程已经完成的注册贡献。

常用命令：

```bash
php artisan runtime:validate
php artisan runtime:cache
php artisan runtime:cache --clear
```

插件安装、升级、启用、禁用和卸载，以及代码 Schema 发布都会清理 Runtime 缓存。安装和升级不在当前 PHP 进程重载同名插件类，新贡献由下一次启动进程和显式校验命令验证；启用、禁用和卸载会在最终状态边界按 Owner 替换或移除贡献后校验。

## 诊断原则

Runtime 诊断至少包含稳定错误编码和受影响的资源或 Action。对外响应不暴露绝对路径、容器实例、签名材料或插件包内部状态。常见编码包括：

- `action_binding_missing`
- `action_binding_duplicate`
- `action_not_declared`
- `runtime_service_invalid`
- `runtime_method_invalid`
- `runtime_validation_failed`
- `event_recursion_blocked`

普通请求不会为了修复诊断而触发 Schema 同步、扫描插件或重建缓存。维护者应在发布或插件生命周期流程中修正注册与 active Schema 的不一致，再显式执行校验和缓存重建。
