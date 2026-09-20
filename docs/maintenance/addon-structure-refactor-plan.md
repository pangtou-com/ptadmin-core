# Addon 结构调整任务计划

## 执行状态

结构调整已于 2026-09-20 完成。源码、测试和宿主调用方已迁移到目标目录；Core 全量测试与 PHPStan 已通过，宿主集成测试结果记录在本文末尾。

## 目标

当前 `src/Addon/` 同时平铺插件包处理、生命周期、运行时注册、资源同步、前端发布和模板指令能力。调用方需要在同一目录中辨认多条无关流程，模板解析与执行也被错误表达为插件体系的内部能力。

本次调整按业务子模块重新组织源码，并将通用模板能力提取到 `PTAdmin\Template`。调整后继续由单一 Composer 包 `ptadmin/core` 发布，不恢复独立 `ptadmin-addon` 包。

## 边界

- `PTAdmin\Addon` 负责插件发现、manifest、包处理、生命周期、运行时接入、资源同步和插件前端模块。
- `PTAdmin\Template` 负责指令定义、参数解析、模板渲染和 Core 内置指令。
- `PTAdmin\Template` 不依赖 `PTAdmin\Addon`。
- 插件指令扫描属于 Addon Runtime，通过 `TemplateDirectiveLoader` 向通用 `DirectiveRegistry` 注册。
- 本次不修改 manifest、任务类型、生命周期状态、模板语法、API 响应或用户资产处理行为。
- 当前仍处于重构阶段，命名空间直接迁移，不保留旧类 alias 或兼容层。

## 目标目录

```text
src/
├── Addon/
│   ├── Catalog/
│   ├── Exception/
│   ├── Frontend/
│   ├── Lifecycle/
│   ├── Manifest/
│   ├── Package/
│   ├── Resource/
│   └── Runtime/
│       └── Contracts/
└── Template/
    ├── Builtin/
    ├── Directive/
    ├── Parsing/
    └── Runtime/
```

## 依赖方向

```text
Core Controller / Task
        ↓
Addon Lifecycle
   ↓       ↓       ↓
Package  Resource  Frontend

Addon Runtime ─────→ Template Directive
Addon Runtime ─────→ Schema Runtime

Template ──禁止──→ Addon
```

`PTAdminServiceProvider` 仍是包的统一 Provider，但按 Template、Addon Manifest、Frontend、Runtime 和 Lifecycle 分段注册服务。目录拆分不引入额外 Laravel Provider 和启动顺序。

## 执行步骤

1. 提取 `PTAdmin\Template`，迁移指令定义、Registry、参数解析、渲染器和内置指令。
2. 新增 `Addon\Runtime\TemplateDirectiveLoader`，把 `Directives/*.php` 扫描从 Runtime Registrar 中分离。
3. 将 Addon 类迁移到 Catalog、Manifest、Package、Lifecycle、Runtime、Resource、Frontend 和 Exception 子命名空间。
4. 更新 Core Provider、Controller、任务执行器、Admin、Schema 和 `ptadmin-dev` 插件调用方。
5. 按源码目录重组单元测试，并增加 `Template` 不得依赖 `Addon` 的架构测试。
6. 更新维护文档中的类名、源码路径和完成状态。
7. 运行 `ptadmin-core` 全量测试与 PHPStan，再运行 `ptadmin-dev` Addon、Host 和 Schema 集成测试。

## 验收条件

- `src/Addon/` 根目录不再平铺 PHP 类。
- 通用模板源码位于 `src/Template/`，且不引用 `PTAdmin\Addon`。
- 插件指令仍从 `Directives/*.php` 自动发现，完整名称、alias、参数元数据和渲染结果保持不变。
- 插件安装、启用、禁用、卸载、Runtime、Schema、菜单和前端模块行为保持现状。
- 源码和测试中不再引用旧的 `PTAdmin\Addon\AddonTemplate*` 等平铺命名空间。
- `composer test`、`composer phpstan` 和指定宿主集成测试通过。

## 非目标

- 不处理路由认证绕过、原始 HTML 安全声明、插件信任模型和生命周期 fail-open 问题。
- 不调整插件 code 规范、manifest 字段或前端 sandbox 协议。
- 不重写模板语法，不引入 AST、缓存或新的模板依赖。
- 不修改数据库结构、迁移或公开 API 响应。

## 执行结果

- `src/Addon/` 已按 Catalog、Manifest、Package、Lifecycle、Runtime、Resource、Frontend 和 Exception 分层，根目录不再放置 PHP 类。
- 通用模板能力已迁入 `src/Template/`，插件指令发现由 `Addon\Runtime\TemplateDirectiveLoader` 接入 `Template\Directive\DirectiveRegistry`。
- `PTAdminServiceProvider` 保持单一公开 Provider，并按 Template、Addon Manifest、Frontend、Runtime 和 Lifecycle 分段注册服务。
- Core 单元测试已按模块目录重组，并新增 `ModuleDependencyTest` 固化 Template 不依赖 Addon、Addon 根目录不平铺 PHP 类两项约束。
- `ptadmin-dev` 的 Demo 插件和集成测试已切换到新命名空间。

## 验证记录

- `cd ptadmin-core && composer test`：123 tests / 675 assertions，OK。
- `cd ptadmin-core && composer phpstan`：286 files，No errors。
- `cd ptadmin-dev && ./vendor/bin/phpunit --filter Addon`：16 tests / 171 assertions，OK。
- `cd ptadmin-dev && ./vendor/bin/phpunit --filter HostContractTest`：11 tests / 121 assertions，OK。
- `cd ptadmin-dev && ./vendor/bin/phpunit --filter SchemaCommandTest`：1 test / 24 assertions，OK。
- `cd ptadmin-dev && ./vendor/bin/phpunit`：47 tests / 822 assertions，OK。
