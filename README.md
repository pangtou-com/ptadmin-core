# PTAdmin Core

`ptadmin/core` 是 PTAdmin 重构后的后端核心包，正式源码目录为当前仓库的 `src/`。核心包统一维护 `Admin`、`Addon`、`Schema` 和 `Core`，并提供 `Notice`、`Template` 和 `Cloud` 等能力；Schema Runtime 是标准资源和 CRUD 的唯一运行时基础设施。

当前阶段已完成：

- Composer 包骨架和 PHP/Laravel 运行约束。
- `Core` Provider、`ptadmin.php` 基础配置和 Schema Provider 注册。
- `ptadmin.json` 应用身份生成、加载、显式重建和原子写入。
- 已安装模式提供匿名 `/ptadmin/bootstrap` 路由；未安装模式不启动 Admin 前端协议。
- `X-Request-ID` 请求追踪中间件和 `code/message/data` 响应工厂基础。
- Schema 协议、定义、发布与 Runtime 统一维护在 `src/Schema/`，不依赖独立历史包。
- 安装状态目录、进程锁、随机后台前缀和 `installed.json` 原子标记基础。
- 首次安装后端编排：SQLite/MySQL 连接配置与验证、数据库迁移、自定义或随机后台前缀写入、首个 founder 管理员、核心权限资源和可恢复失败状态；SQLite 文件固定存放在宿主 `storage/database/`，默认文件名为 `ptadmin.db`，安装表单可以修改文件名但不能修改目录。
- 核心前端 manifest 校验和首次原子发布到 `public/{PTADMIN_WEB_PREFIX}/`，发布时保留插件 `plugins/` 目录。
- Schema Runtime 标准资源路由、Session SubjectContext 和统一授权桥接。
- 配置定义和值两张表、固定分组、全局 JSON 对象值和敏感字段脱敏服务。
- 不透明 Bearer Session 存储、过期/撤销/续期服务和 `/ptadmin/session` 基础接口。
- Admin 管理闭环：管理员、角色和权限资源作为代码型 Schema Resource 提供统一 CRUD；密码、角色关系、直接授权和会话撤销通过受控 Runtime Hook/Action 执行。
- 通用数据范围按主体、资源和授权记录保存，Core 内置 `all` 与 `self`；`self` 通过资源 Schema 的 `extensions.authorization.owner_field` 绑定持久化所有者字段，业务模块可通过 `DataScopeRegistry` 注册额外范围。
- Core 第一版按单应用、单管理域运行，不内置租户、组织、部门和管理员组织归属；多租户商城及 ERP、CRM 的组织模型由对应业务模块在需求成立后提供。
- Admin 个人资料、本人密码修改和本人会话管理接口；密码修改、账号禁用和管理员动作可撤销现有 Bearer Session。
- Admin 登录成功/失败记录与后台写操作审计；登录日志和操作记录作为只读 Schema Resource 提供查询。
- 资源中心通过 `/ptadmin/assets` 提供查询、详情、元数据更新、软删除和恢复；公开上传统一进入 `/ptadmin/storage/upload` 并创建资源记录，私有上传只作为后端服务提供，不进入资源中心。
- 创始人、系统角色和核心权限资源保护，以及按资源/动作执行的服务端权限检查。
- 未安装启动阶段提供安装状态查询接口 `GET /__ptadmin__/install/status`，不暴露敏感安装输入；安装完成后该路由不再注册。
- 独立 `GET /__ptadmin__/install` 安装向导，复用原 PTAdmin 分步 UI，提供协议确认、PHP/扩展与目录权限检查、基础信息、SQLite/MySQL 数据库配置、创始人账号表单和流式安装进度；数据库连接、`APP_KEY` 和核心前端 manifest 在实际安装阶段校验，不单独展示“运行环境”检测分组。完成后进入自定义或随机后台目录，已安装实例返回 404。
- 已安装实例通过 `PTADMIN_WEB_PREFIX` 提供后台入口和 SPA fallback；入口由 Core 路由校验 `installed.json`，静态 assets 与插件 plugins 仍由 Web Server 直接提供。
- Core 在服务启动时读取安装标记并选择唯一运行模式：未安装时只加载 `routes/install.php`、安装视图和宿主 Web 重定向，不加载 Bootstrap、登录、Session、Schema HTTP、插件 Runtime 或 PTAdmin 运维命令；已安装时只加载 `routes/ptadmin.php` 和正常 Runtime，安装路由不可访问。安装 API 为 `POST /__ptadmin__/install`，状态接口为 `GET /__ptadmin__/install/status`。
- JSONL 任务中心：任务文件位于 `storage/app/ptadmin/tasks/{task_id}/`，后台接口提供任务创建、状态、事件 cursor、重试和取消；任务支持幂等键、心跳恢复和不可逆阶段保护，快照持久化当前消息并派生 `can_retry`/`can_cancel`，事件/载荷/结果统一脱敏并使用文件锁；核心前端可通过独立发布器校验清单后原子更新，同时保留插件 `plugins/` 目录。
- 任务执行通过 `php artisan ptadmin:task:run {task_id}` 在独立进程中完成。执行器采用 `TaskExecutorRegistry` 扩展，目前内置 `admin_frontend.update`，未注册的核心/插件任务会进入 `blocked`，不会在 PHP-FPM 请求中伪成功。
- 可使用 `php artisan ptadmin:task:recover` 扫描超时心跳任务；默认按重试次数重新排队，也可通过 `--no-requeue` 直接标记失败。
- 插件信任链基础位于 `src/Addon`：`Manifest\ManifestVerifier` 校验 manifest、文件清单、摘要、兼容范围和 OpenSSL 签名；`Lifecycle\LifecycleStore` 持久化插件生命周期状态并防止非法状态跳转。
- `Frontend\FrontendPublisher` 将插件前端以临时目录加原子替换发布到 `public/{PTADMIN_WEB_PREFIX}/plugins/{code}`，失败时保留旧版本，卸载时仅删除对应插件目录。

Schema Hook、Action、Resource Event、Owner、校验和缓存规则见 [Schema Runtime 扩展](docs/schema-runtime-extensions.md)。

## 插件 PHP 加载

插件根目录使用小写 code，PHP 类放在与 namespace 大小写一致的 `src/` 中。Core 通过共享独立 Composer ClassLoader 注册标准 PSR-4 映射，在启动、安装、迁移、启停、资源同步及 Runtime 预检使用类之前准备映射；插件操作不修改宿主 Composer、vendor 或 IDE 配置。插件 `composer.json` 提供静态映射供开发工具使用。完整约定见聚合工作区 `ptadmin-docs/reference/plugin-php-loading.md`。

## 代码型 Schema 文件

宿主应用、Core 和插件使用相同的目录结构维护代码型 Schema：

```text
database/schemas/**/*.schema.json
database/schemas/**/*.schema.jsonc
```

宿主目录为 `database/schemas/`，插件目录为 `addons/{code}/database/schemas/`。`.schema.json` 使用严格 JSON，`.schema.jsonc` 可以使用行注释、块注释和尾逗号。每个文件只声明一个资源，文件名必须与 `resource` 一致，例如 `cms.article.schema.jsonc` 必须声明 `cms.article`。

执行 `php artisan schema:sync` 会先扫描并校验所有文件，再将发生变化的资源同步到草稿。迁移计划为 `safe` 时会自动发布；`guarded` 或 `destructive` 变更会保留为草稿并返回失败状态。使用 `php artisan schema:sync --draft` 可以让所有变化只进入草稿。发布指定资源使用：

```bash
php artisan schema:publish cms.article
php artisan schema:publish cms.article --force
```

`--force` 只用于批准指定资源的破坏性变更，不提供批量强制发布。代码资源文件被删除时不会自动删除数据库资源、表或业务数据。

Laravel 迁移统一放在 `database/migrations/`；插件使用 `addons/{code}/database/migrations/`。`migration_owner: schema` 由 Schema 发布流程维护表结构，`migration_owner: domain` 则由开发者编写并通过 Laravel `migrate` 命令执行迁移，`schema:sync` 不代替应用迁移。

更新 `ptadmin/core` 后先执行 Laravel 迁移，再同步后台内置 Schema、菜单资源和配置：

```bash
php artisan migrate --force
php artisan ptadmin:menu:sync
php artisan ptadmin:admin:sync
```

`ptadmin:menu:sync` 负责读取 Core 和已安装插件的 `resources/menu.json` 并幂等同步 `admin_resources`。安装流程和插件生命周期直接调用相同的同步服务，不在进程内嵌套调用 Artisan 命令。`ptadmin:admin:sync` 负责 Admin 内置 Schema、权限关联、配置定义和 Core 字典；应用与插件代码型 Schema 仍由 `schema:sync` 或插件安装流程维护。

Core 内置配置定义位于 `resources/config.site.json`、`resources/config.mail.json` 和 `resources/config.upload.json`，与插件的 `resources/config.*.json` 使用相同的配置协议。配置同步以 JSON 文件为定义来源，并在同步时注入当前 Laravel 配置中的运行时默认值。

后台 Admin 资源和导航统一存储在 `admin_resources`。Core 资源声明位于 `resources/menu.json`，宿主插件资源声明位于 `addons/{code}/resources/menu.json`；两个文件使用相同的严格 JSON 数组格式，声明完整资源集合，不再使用 `Admin/resources.json`。资源类型为 `group`、`schema`、`page`、`external`、`action`，其中 `action` 只参与授权，其他类型通过 `is_menu` 决定是否进入 Session 菜单。安装、`ptadmin:admin:sync` 和插件生命周期共用幂等资源同步逻辑。

授权的数据范围位于 `admin_grants.scope_type` 和 `admin_grants.scope_value_json`，角色本身不保存全局范围。`all` 表示不附加记录条件；`self` 要求活动 Schema 在 `extensions.authorization.owner_field` 中显式指定持久化所有者字段。未知范围、非法范围参数、缺少活动 Schema 或缺少所有者声明时，Scope Resolver 返回空记录约束。多个同优先级允许授权以 OR 合并；同优先级拒绝授权仍优先。业务模块注册的范围处理器只能返回结构化 `ServerConstraint`，客户端筛选不能覆盖该约束。

## 数据字典

数据字典定义和条目分别存储在 `admin_dicts`、`admin_dict_items`。后台管理接口保持业务语义明确的 `/ptadmin/dictionaries`，读取、创建、更新、删除分别使用 `admin.dictionaries.view/create/update/delete` 权限。自定义字典固定采用 `editable` 策略；字典编码和值类型、字典项的选项值创建后不可修改。字典项不再单独保存条目编码，`value` 同时承担字典内唯一稳定值。删除使用软删除，已经使用过的字典编码和选项值不再复用。

Core 和插件字典通过 `DictionaryProviderInterface` 使用 PHP 链式 API 声明，不再使用 JSON 配置文件。Core 在 `CoreDictionaryProvider` 中注册，插件约定提供 `src/DictionaryProvider.php`，类名为插件 manifest namespace 下的 `DictionaryProvider`。声明包含 `code`、`title`、`valueType()`、`closed()/appendable()` 和 `addItem()/item()/items()`。安装、插件安装/升级以及 `ptadmin:admin:sync` 会调用同一个幂等同步器；插件停用或声明移除只停用相应字典，不删除业务历史值。

Core 默认声明数字型 `system.status` 通用状态字典，包含选项值 `1`（启用）和 `0`（禁用）。Schema 的 boolean 字段继续使用布尔组件；需要引用该字典的业务字段应使用 `integer` 或 `number` 类型。

示例：

```php
$registry
    ->define('order.status', '订单状态')
    ->description('订单业务状态')
    ->exposure('public')
    ->appendable()
    ->addItem('pending', '待处理')
    ->addItem('paid', '已支付', ['type' => 'success'], true, 20)
    ->item(['value' => 'closed', 'label' => '已关闭']);
```

Schema 字段通过以下协议引用字典：

```json
{
    "type": "string",
    "options": {
        "mode": "dictionary",
        "value_policy": "closed",
        "dictionary": { "code": "order.status" }
    }
}
```

Runtime 候选项只包含启用条目，但会为已选历史值补回已经停用或软删除的条目标签；`value_policy: closed` 会拒绝不属于当前启用条目的新值，并检查 Schema 字段类型与字典 `value_type` 是否一致。公开读取使用 `GET /ptadmin/public/dictionaries/{code}`，只返回 `exposure: public` 字典的启用条目，不能借用后台管理接口绕过认证。

## 资源中心与文件上传

资源中心只管理公开文件，接口使用 `assets.view`、`assets.update`、`assets.delete` 和 `assets.restore` 权限：

```text
GET    /ptadmin/assets
GET    /ptadmin/assets/{id}
PATCH  /ptadmin/assets/{id}
DELETE /ptadmin/assets/{id}
POST   /ptadmin/assets/{id}/restore
```

公开上传使用 `POST /ptadmin/storage/upload` 和 `assets.upload` 权限。该接口写入公开磁盘并创建 `assets` 记录；资源摘要统一使用 SHA-256。`group_code` 在上传时确定并参与对象路径，创建后不可修改，`PATCH /ptadmin/assets/{id}` 只允许更新展示标题。删除资源只写入 `deleted_at`，不删除物理文件。资源选择器和 Schema 上传字段复用相同的资源列表与公开上传接口。

后端业务需要保存合同、导入文件等私有内容时，调用 `AssetUploadService::uploadPrivate()`。私有上传返回磁盘、Bucket、对象路径、文件摘要和元数据，不创建 `assets` 记录，也不提供通用 HTTP 接口；调用业务负责保存文件定位信息、鉴权访问和清理文件。

存储配置位于 `ptadmin.storage`，可通过 `PTADMIN_PUBLIC_UPLOAD_DISK`、`PTADMIN_PRIVATE_UPLOAD_DISK`、`PTADMIN_PUBLIC_UPLOAD_DIRECTORY`、`PTADMIN_PRIVATE_UPLOAD_DIRECTORY` 和 `PTADMIN_MAX_UPLOAD_KILOBYTES` 调整。公开磁盘必须支持生成可访问 URL，私有磁盘不要求公开 URL。

应用身份文件固定位于 Laravel 项目的 `storage/ptadmin.json`，不提供环境变量或配置覆盖，并与可清理的 `storage/app/` 运行时文件分离。文件由 Core 以 `0600` 权限写入；这可以限制其他用户读取私钥，但不能替代备份或阻止文件所有者删除。`load()` 不会隐式重建缺失或损坏的身份；只有安装流程调用 `createForInstall()`，或管理员显式执行重建流程时，才会写入新身份。

后续实现顺序按聚合工作区根目录的 [`TASKS.md`](../TASKS.md) 执行，集成验证统一在 `ptadmin-dev/` 完成。
