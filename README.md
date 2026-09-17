# PTAdmin Core

`ptadmin/core` 是 PTAdmin 重构后的后端核心包，正式源码目录为当前仓库的 `src/`。核心包最终包含 `Admin`、`Addon`、`Schema` 和 `Core` 四个模块；Schema Runtime 是标准资源和 CRUD 的唯一运行时基础设施。

当前阶段已完成：

- Composer 包骨架和 PHP/Laravel 运行约束。
- `Core` Provider、`ptadmin.php` 基础配置和 Schema Provider 注册。
- `ptadmin.json` 应用身份生成、加载、显式重建和原子写入。
- 匿名 `/ptadmin/bootstrap` 路由基础。
- `X-Request-ID` 请求追踪中间件和 `code/message/data` 响应工厂基础。
- `ptadmin-schema` 协议与 Runtime 源码提取到 `src/Schema/`。
- 安装状态目录、进程锁、随机后台前缀和 `installed.json` 原子标记基础。
- 首次安装后端编排：SQLite/MySQL 连接配置与验证、数据库迁移、自定义或随机后台前缀写入、首个 founder 管理员、核心权限资源和可恢复失败状态。
- 核心前端 manifest 校验和首次原子发布到 `public/{PTADMIN_WEB_PREFIX}/`，发布时保留插件 `modules/` 目录。
- Schema Runtime 标准资源路由、Session SubjectContext 和统一授权桥接。
- 配置定义和值两张表、固定分组、全局 JSON 对象值和敏感字段脱敏服务。
- 不透明 Bearer Session 存储、过期/撤销/续期服务和 `/ptadmin/session` 基础接口。
- Admin 管理闭环：管理员、角色和权限资源作为代码型 Schema Resource 提供统一 CRUD；密码、角色关系、直接授权和会话撤销通过受控 Runtime Hook/Action 执行。
- Admin 个人资料、本人密码修改和本人会话管理接口；密码修改、账号禁用和管理员动作可撤销现有 Bearer Session。
- Admin 登录成功/失败记录与后台写操作审计；登录日志和操作记录作为只读 Schema Resource 提供查询。
- 创始人、系统角色和核心权限资源保护，以及按资源/动作执行的服务端权限检查。
- 安装状态查询接口 `GET /ptadmin/install/status`，不暴露敏感安装输入。
- 独立 `GET /__ptadmin__/install` 安装向导，复用原 PTAdmin 分步 UI，提供协议确认、PHP/扩展与目录权限检查、基础信息、SQLite/MySQL 数据库配置、创始人账号表单和流式安装进度；数据库连接、`APP_KEY` 和核心前端 manifest 在实际安装阶段校验，不单独展示“运行环境”检测分组。完成后进入自定义或随机后台目录，已安装实例返回 404。
- 已安装实例通过 `PTADMIN_WEB_PREFIX` 提供后台入口和 SPA fallback；入口由 Core 路由校验 `installed.json`，静态 assets 与插件 modules 仍由 Web Server 直接提供。
- Core 注册 `ptadmin.install` 中间件别名；宿主可将其挂到自己的 Web 路由组，在未安装时把业务页面统一引导到保留安装地址。
- JSONL 任务中心：任务文件位于 `storage/app/ptadmin/tasks/{task_id}/`，后台接口提供任务创建、状态、事件 cursor、重试和取消；任务支持幂等键、心跳恢复和不可逆阶段保护，快照持久化当前消息并派生 `can_retry`/`can_cancel`，事件/载荷/结果统一脱敏并使用文件锁；核心前端可通过独立发布器校验清单后原子更新，同时保留插件 `modules/` 目录。
- 任务执行通过 `php artisan ptadmin:task:run {task_id}` 在独立进程中完成。执行器采用 `TaskExecutorRegistry` 扩展，目前内置 `admin_frontend.update`，未注册的核心/插件任务会进入 `blocked`，不会在 PHP-FPM 请求中伪成功。
- 可使用 `php artisan ptadmin:task:recover` 扫描超时心跳任务；默认按重试次数重新排队，也可通过 `--no-requeue` 直接标记失败。
- 插件信任链基础位于 `src/Addon`：`AddonManifestVerifier` 校验 manifest、文件清单、摘要、兼容范围和 OpenSSL 签名；`AddonLifecycleStore` 持久化插件生命周期状态并防止非法状态跳转。
- `AddonFrontendPublisher` 将插件前端以临时目录加原子替换发布到 `public/{PTADMIN_WEB_PREFIX}/modules/{code}`，失败时保留旧模块，卸载时仅删除对应模块目录。

## 代码型 Schema 文件

宿主应用、Core 和插件使用相同的目录结构维护代码型 Schema：

```text
database/schemas/**/*.schema.json
database/schemas/**/*.schema.jsonc
```

宿主目录为 `database/schemas/`，插件目录为 `addons/{Code}/database/schemas/`。`.schema.json` 使用严格 JSON，`.schema.jsonc` 可以使用行注释、块注释和尾逗号。每个文件只声明一个资源，文件名必须与 `resource` 一致，例如 `cms.article.schema.jsonc` 必须声明 `cms.article`。

执行 `php artisan schema:sync` 会先扫描并校验所有文件，再将发生变化的资源同步到草稿。迁移计划为 `safe` 时会自动发布；`guarded` 或 `destructive` 变更会保留为草稿并返回失败状态。使用 `php artisan schema:sync --draft` 可以让所有变化只进入草稿。发布指定资源使用：

```bash
php artisan schema:publish cms.article
php artisan schema:publish cms.article --force
```

`--force` 只用于批准指定资源的破坏性变更，不提供批量强制发布。代码资源文件被删除时不会自动删除数据库资源、表或业务数据。

Laravel 迁移统一放在 `database/migrations/`；插件使用 `addons/{Code}/database/migrations/`。`migration_owner: schema` 由 Schema 发布流程维护表结构，`migration_owner: domain` 则由开发者编写并通过 Laravel `migrate` 命令执行迁移，`schema:sync` 不代替应用迁移。

应用身份文件默认位于 Laravel 项目的 `storage/app/ptadmin/ptadmin.json`。`load()` 不会隐式重建缺失或损坏的身份；只有安装流程调用 `createForInstall()`，或管理员显式执行重建流程时，才会写入新身份。

后续实现顺序按聚合工作区根目录的 [`TASKS.md`](../TASKS.md) 执行，集成验证统一在 `ptadmin-dev/` 完成。
