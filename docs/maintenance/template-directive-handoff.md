# 前台模板指令协议交接

本文档用于下一阶段继续实现 PTAdmin 前台模板指令运行时。交接范围仅包含 `ptadmin-core` 的 Addon 模板指令能力，不包含后台前端 API、Host API、插件前端模块加载或 CMS 业务接口。

## 当前状态

已完成协议讨论和文档收敛，尚未开始新的模板编译器和结构化运行时实现。

当前 Core 已有以下基础类：

- `src/Addon/AddonTemplateDirectiveInterface.php`
- `src/Addon/AddonTemplateDirectiveRegistry.php`
- `src/Addon/AddonRuntimeRegistrar.php`

现有实现仍按旧过渡方式从 `manifest.json` 的 `directives` 字段读取文件和类，并调用注册类。该实现只用于接口校验和迁移阶段，不能作为最终协议。

## 已确认的正式协议

### 指令发现

`manifest.json` 不保留 `directives` 字段。Core 固定扫描插件目录：

```text
addons/{Code}/Directives/*.php
```

发现规则：

- 只扫描这一层目录，不递归扫描插件其他目录。
- 按文件路径排序后加载，保证注册顺序稳定。
- 只有实现 `AddonTemplateDirectiveInterface` 的类才是指令注册类。
- `Directives/` 中的一个类可以注册多个指令。
- 辅助类放在 `Support/` 等其他目录，不放在 `Directives/` 中。
- 没有 `Directives/` 目录的插件表示没有模板指令，不视为错误。
- 指令文件必须进入插件签名和文件清单。
- 一个插件的指令先写入临时注册表，全部校验通过后再一次性合并到全局注册表，避免部分注册。

### 指令名称

插件注册时只声明指令名，例如：

```text
articles
banner
```

Core 根据插件 code 自动生成完整名称：

```text
<addon_code>_<directive_name>
```

示例：

```text
cms_articles
demo_banner
```

完整名称必须全局唯一。插件 code 是插件身份的一部分，本协议不要求改名；新指令名使用小写 snake_case。

### 别名

别名必须显式声明，不自动从指令名生成：

```php
->alias('articles')
```

没有显式 alias 时，只能使用完整名称：

```blade
@pt:cms_articles(limit=10,as=item)
```

显式 alias 后才允许短写法：

```blade
@pt:articles(limit=10,as=item)
```

第一版每个指令最多声明一个主别名。别名必须全局唯一；与其他插件冲突时注册失败，不能静默移除，也不能通过 `default_addon` 选择默认目标。

### 指令类型

第一版只支持：

- `output`：直接输出文本或安全输出对象。
- `loop`：返回可遍历数据，并在模板块内绑定当前项。

条件判断使用 Blade 的 `@if`、`@elseif` 和 `@else`，不新增第三套条件指令。

### 循环变量

循环指令使用 `as`：

```blade
@pt:cms_articles(limit=10,as=item)
    {$item.title}
@pt:empty
    暂无文章
@pt:end
```

规则：

- `as` 只能是简单变量名。
- 未指定 `as` 时默认变量名为 `field`。
- `@pt:end` 和 `@pt:empty` 是固定结构关键字，不是插件自定义指令。
- 嵌套循环有独立变量作用域，内层结束后恢复外层变量。
- 新协议使用 `as`，不再使用历史 `id`。

### 字段输出

模板支持轻量字段输出：

```blade
{$item.title}
{$item.category.name}
{$item.title|default="暂无标题"}
```

字段读取使用受限的路径访问语义，缺失值输出空字符串，默认自动 HTML 转义。不允许 `->`、函数调用、运算、三元表达式或任意 PHP 代码。

原始 HTML 需要显式语法，并且必须通过插件注册的安全声明：

```blade
{: $item.content}
```

模板语法不能绕过插件的安全 HTML 限制。

### 参数元数据

参数定义同时用于运行时校验、错误提示、文档生成、IDE 补全和模板编辑器提示。每个参数至少应支持：

```text
name
type
required
default
description
example
enum
min
max
item_type
deprecated
```

示例：

```php
->parameter(
    'limit',
    type: 'integer',
    description: '最多返回的文章数量。',
    required: false,
    default: 10,
    min: 1,
    max: 100,
    example: 10,
)
```

`as` 是所有 loop 指令的运行时保留参数，不由插件重复声明；`@pt:empty` 和 `@pt:end` 的说明由文档生成器自动补充。

### 注册类示意

一个注册类可以定义多个指令：

```php
final class CmsTemplateDirectives implements AddonTemplateDirectiveInterface
{
    public function register(
        AddonTemplateDirectiveRegistry $registry,
        string $code,
        array $manifest,
    ): void {
        $registry
            ->define('articles')
            ->loop([$this, 'articles'])
            ->alias('articles')
            ->description('读取 CMS 文章列表。')
            ->parameter('limit', type: 'integer', description: '最多返回的文章数量。', default: 10, min: 1, max: 100);

        $registry
            ->define('banner')
            ->output([$this, 'banner'])
            ->alias('banner')
            ->description('输出指定位置的广告内容。')
            ->parameter('position', type: 'string', description: '广告位编码。', required: true, example: 'home');
    }
}
```

上例由 Core 自动生成 `cms_articles` 和 `cms_banner`。最终 API 命名可以在实现时调整，但不能改变上述语义。

## 当前代码与协议的差异

下一阶段必须处理以下差异：

1. `AddonRuntimeRegistrar::registerTemplateDirectives()` 当前读取 manifest `directives`，需要改为扫描 `Directives/*.php`。
2. `AddonTemplateDirectiveRegistry` 当前只保存 `name/addon/handler`，需要增加完整名称、显式 alias、类型、参数元数据、说明、上下文、缓存和安全输出声明。
3. 当前注册表允许点号和连字符名称，最终指令名和 alias 应按协议校验 snake_case。
4. 当前重复名称会在全局注册过程中直接抛错，需要增加插件级临时注册和一次性提交。
5. 现有 `AddonRuntimeRegistrarTest` 中通过 manifest 声明指令的测试需要迁移到目录自动发现测试。
6. `ptadmin-dev/addons/Demo/manifest.json` 不应新增 `directives` 字段；Demo 指令类应放到 `Directives/`。

## 实现顺序

建议按以下顺序实现：

1. 设计不可变的 `TemplateDirectiveDefinition`、参数定义对象和注册构建器。
2. 扩展 Registry，支持 `define()->output()/loop()->alias()->description()->parameter()` 链式注册。
3. 实现插件级临时 Registry 和提交接口。
4. 修改 Runtime Registrar，固定扫描 `Directives/*.php`，校验接口并按排序加载。
5. 实现完整名称和显式 alias 的唯一性校验。
6. 增加注册元数据导出，为文档和 IDE 提供稳定数组或 DTO。
7. 再实现模板解析器、`TemplateDirectiveInvocation`、`{$item.title}` 输出和 loop 上下文。
8. 在 `ptadmin-dev/addons/Demo` 增加 `demo_banner` 和 `demo_articles` 验收案例。

## 必须覆盖的测试

- `Directives/` 自动发现和稳定排序。
- 未实现 `AddonTemplateDirectiveInterface` 的类被拒绝。
- 一个注册类成功注册多个指令。
- 没有 `Directives/` 目录的插件正常加载。
- 完整名称冲突导致插件注册失败且不留下部分定义。
- 显式 alias 正常调用。
- 未声明 alias 时短名称不可调用。
- alias 冲突导致注册失败，并报告两个冲突来源。
- 参数类型、必填、默认值、枚举和范围校验。
- 参数说明和示例可以导出为文档元数据。
- `as`、默认 `field`、`@pt:empty`、嵌套循环变量恢复。
- `{$item.title}` 默认转义、缺失值和默认值处理。

## 边界和禁止事项

- 模板指令服务站点前台模板，不进入后台前端 API。
- 模板阶段默认只能读取 Schema Runtime，不能写数据库或执行后台管理动作。
- 不恢复旧 `PTCompiler` 的全局单例、旧 Facade、Injects 或任意 PHP 表达式。
- 不把 CMS 业务查询硬编码进 Core；Core 提供注册和运行时，业务实现留在插件。
- 不修改另一会话负责的宿主路由和前后端联调代码。

## 交接后的验收标准

完成注册运行时后，应能在 Demo 模板中使用：

```blade
@pt:demo_banner(position="home")

@pt:demo_articles(limit=2,as=item)
    <h2>{$item.title|default="暂无标题"}</h2>
@pt:empty
    暂无文章
@pt:end
```

并能从 Registry 导出 `demo_banner`、`demo_articles` 的类型、说明、参数和 alias 元数据。完成模板编译器后，再补充真实渲染、转义、空数据和嵌套上下文验收。

## 本次提交范围

本次只提交本交接文档，不提交当前 `ptadmin-core` 中其他会话已有的代码、测试或配置改动。后续实现应单独提交，避免把协议、运行时和前端联调混在一个版本中。
