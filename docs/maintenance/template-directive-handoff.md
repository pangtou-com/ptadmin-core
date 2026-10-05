# 前台模板指令协议交接

本文档记录 PTAdmin 前台模板指令运行时的协议和当前实现。范围仅包含 `ptadmin-core` 的通用 Template 能力及 Addon 指令接入，不包含后台前端 API、Host API、插件前端模块加载或 CMS 业务接口。

## 当前状态

结构化注册、参数解析和模板渲染运行时已经实现。通用能力位于 `PTAdmin\Template`，插件目录扫描和加载位于 `PTAdmin\Addon\Runtime`，Template 不反向依赖 Addon。

当前主要实现入口：

- `src/Template/Directive/DirectiveRegistry.php`
- `src/Template/Parsing/DirectiveArgumentParser.php`
- `src/Template/Runtime/TemplateRenderer.php`
- `src/Template/Builtin/BuiltinDirectiveProvider.php`
- `src/Addon/Runtime/Contracts/TemplateDirectiveProviderInterface.php`
- `src/Addon/Runtime/TemplateDirectiveLoader.php`

Addon Runtime 固定扫描 `Directives/*.php`，在临时 Registry 完成插件级注册后一次性合并到全局 Registry；`manifest.json` 不声明 `directives` 字段。

## 已确认的正式协议

### 指令发现

`manifest.json` 不保留 `directives` 字段。Core 固定扫描插件目录：

```text
addons/{Code}/Directives/*.php
```

发现规则：

- 只扫描这一层目录，不递归扫描插件其他目录。
- 按文件路径排序后加载，保证注册顺序稳定。
- 只有实现 `TemplateDirectiveProviderInterface` 的类才是指令注册类。
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

第一版每个指令最多声明一个主别名。当前 Registry 允许多个插件显式声明同一别名；调用歧义别名时抛错并列出可用完整名称，不能静默选择，也不能通过 `default_addon` 选择默认目标。Core 媒体与结构关键字仍保留，插件不能占用。完整名称冲突导致整个批次注册失败。

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
- 推荐 `as`，过渡期兼容历史 `id`；两者同时提供时必须同名。已声明业务参数 id 的指令不做变量名转换。

### 字段输出

模板支持轻量字段输出：

```blade
{$item.title}
{$item.category.name}
{$item.title|default="暂无标题"}
```

字段读取使用受限的路径访问语义，缺失值输出空字符串，默认自动 HTML 转义。允许括号内固定格式化参数；不允许 `->`、任意函数调用、运算、三元表达式或任意 PHP 代码。

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

参数值解析必须支持字符串、数字、布尔值、`null`、数组字面量和变量引用。变量引用支持 `$item`、`$item.title`、`$page.seo.description`，数组可以递归包含变量。参数解析先生成受限 AST，再做类型校验，不允许 `eval`、方法调用或任意 PHP 表达式。

## Core 内置指令

Core 在插件注册前使用同一 Registry 注册内置指令，并保留以下模板别名：

```text
seo
img
images
file
files
dump
```

插件不能覆盖这些别名。

### 统一媒体指令

`img`、`images`、`file`、`files` 四个指令使用完全一致的参数：

```text
value
key
label
attrs
limit
empty
separator
```

标准媒体对象为：

```php
[
    'key' => '/uploads/cover.jpg',
    'label' => '文章封面',
]
```

输出契约：

```text
img     -> 一个 <img>
images  -> 多个 <img>
file    -> 一个 <a>
files   -> 多个 <a>
```

`images` 和 `files` 是批量输出指令，不是 loop 指令，不使用 `as`、`@pt:empty`、`@pt:end`，也不嵌套其他指令。`key` 默认读取地址字段，`label` 默认读取显示文本字段；图片的 `label` 输出为 `alt`，文件的 `label` 输出为链接文本。

### 媒体 value 自动解析

`value` 省略时，按以下顺序解析：

1. 最近循环上下文的当前项。
2. 宿主提供的当前媒体上下文。
3. Schema 标记的唯一媒体字段或集合字段。
4. 没有明确结果时输出空内容并记录开发诊断。

显式 `value=null` 禁止自动解析。运行时不得扫描所有字段，也不得根据 `cover`、`thumbnail`、`gallery`、`attachments` 等名称进行不确定猜测；多个候选字段必须要求显式 `value`。

媒体渲染器必须限制 URL 协议、转义属性值、拒绝 `on*` 事件属性，并将 `empty`、`separator` 按普通文本处理。媒体指令只消费传入值或 Schema 上下文，不直接查询数据库或文件表。

### 其他内置指令

- `seo()` 输出当前页面 SEO 头部；支持 `only` 数组和 `data` 变量，不通过输出指令隐式修改全局状态。标准字段包括 `title`、`description`、`keywords`、`canonical`、`robots`、`open_graph`、`twitter` 和 `structured_data`；`structured_data` 会以 JSON-LD script 输出。
- `dump()` 仅用于开发调试，生产环境不输出敏感上下文；支持 `context`、`label` 和 `once`。

### 注册类示意

一个注册类可以定义多个指令：

```php
final class CmsTemplateDirectives implements TemplateDirectiveProviderInterface
{
    public function register(
        DirectiveRegistration $registration,
        array $manifest,
    ): void {
        $registration
            ->define('articles')
            ->loop([$this, 'articles'])
            ->alias('articles')
            ->description('读取 CMS 文章列表。')
            ->parameter('limit', type: 'integer', description: '最多返回的文章数量。', default: 10, min: 1, max: 100)
            ->commit();

        $registration
            ->define('banner')
            ->output([$this, 'banner'])
            ->alias('banner')
            ->description('输出指定位置的广告内容。')
            ->parameter('position', type: 'string', description: '广告位编码。', required: true, example: 'home')
            ->commit();
    }
}
```

上例由 Core 自动生成 `cms_articles` 和 `cms_banner`。最终 API 命名可以在实现时调整，但不能改变上述语义。

## 当前实现与后续边界

当前已经完成：

1. 不可变指令定义、参数对象、链式注册、插件级临时 Registry 和批量提交。
2. `Directives/*.php` 自动发现、接口校验、完整名称、显式 alias、冲突检测和元数据导出。
3. 字符串、数字、布尔值、`null`、数组和变量引用参数解析及类型校验。
4. output、loop、`as`、`@pt:empty`、`@pt:end`、字段转义、默认值和嵌套循环上下文。
5. `seo`、`img`、`images`、`file`、`files`、`dump` 内置指令和媒体上下文解析。
6. Demo 插件的 `demo_banner`、`demo_articles` 注册和真实渲染验收。

原始 HTML 字段现在通过 `PTAdmin\Template\Runtime\SafeHtml` 显式声明；普通字符串不能用于 `{: $field}`。CMS 将当前 Schema 的 rich-text 字段及专题富文本包装为 SafeHtml，此包装本身不清洗 HTML。缓存策略和更完整的生产诊断仍为后续议题。

## 必须覆盖的测试

- `Directives/` 自动发现和稳定排序。
- 未实现 `TemplateDirectiveProviderInterface` 的类被拒绝。
- 一个注册类成功注册多个指令。
- 没有 `Directives/` 目录的插件正常加载。
- 完整名称冲突导致插件注册失败且不留下部分定义。
- 显式 alias 正常调用。
- 未声明 alias 时短名称不可调用。
- alias 歧义在调用时报告多个完整名称；完整名称及保留别名冲突在注册时拒绝。
- 参数类型、必填、默认值、枚举和范围校验。
- 参数说明和示例可以导出为文档元数据。
- 字符串、数组、嵌套数组和变量引用参数的 AST 解析与类型校验。
- `as`、默认 `field`、`@pt:empty`、嵌套循环变量恢复。
- `{$item.title}` 默认转义、缺失值和默认值处理。
- 四个媒体指令的统一参数、单项/集合 HTML 输出和安全属性处理。
- `value` 自动媒体上下文解析、显式 `value=null`、多候选字段歧义和无上下文诊断。
- `seo` 的完整/部分输出和 `dump` 的开发环境限制。

## 边界和禁止事项

- 模板指令服务站点前台模板，不进入后台前端 API。
- 模板阶段默认只能读取 Schema Runtime，不能写数据库或执行后台管理动作。
- 不恢复旧 `PTCompiler` 的全局单例、旧 Facade、Injects 或任意 PHP 表达式。
- 不把 CMS 业务查询硬编码进 Core；Core 提供注册和运行时，业务实现留在插件。
- 不修改另一会话负责的宿主路由和前后端联调代码。

## 交接后的验收标准

当前 Demo 模板可以使用：

```blade
@pt:demo_banner(position="home")

@pt:demo_articles(limit=2,as=item)
    <h2>{$item.title|default="暂无标题"}</h2>
@pt:empty
    暂无文章
@pt:end
```

Registry 可以导出 `demo_banner`、`demo_articles` 的类型、说明、参数和 alias 元数据；真实渲染、转义、空数据和嵌套上下文已有测试覆盖。

媒体验收还应覆盖：

```blade
@pt:img()
@pt:images(value=$item.images,key="key",label="label",empty="暂无图片")
@pt:file(value=$item.file)
@pt:files(empty="暂无附件")
```

以上调用分别验证自动上下文、显式集合、统一字段映射、空集合处理和批量 HTML 输出，不使用嵌套指令。

## 当前结构调整范围

本轮只调整 Addon/Template 目录、命名空间、服务注册分组和调用方引用，不修改模板协议、生命周期、公开 API 或既有安全行为。


## 2026-10-05：CMS 模板联调落点

- 循环变量推荐 `as`，兼容旧 `id`。两者同时出现必须同名；指令已声明业务参数 id 时不转换其含义。
- 渲染器先解析结构，再按源顺序执行。list 与 pager 共享一次渲染内的状态，跨渲染和嵌套 render 不泄漏；返回数据不会重新解释成模板源码。
- 当前主题经 `ThemeTemplateEngine` 解释：支持字面名称的 extends、块式 section/endsection、yield、include，及受限 if/elseif/else/endif。条件允许变量、字面量、比较及布尔运算，禁止任意函数/PHP。包含必须位于当前主题，限制循环和深度。
- 简单 Blade 输出 `{{ $title ?? '默认值' }}` 映射为受控字段输出；宿主非主题 Blade 保持 Laravel 引擎，不向主题暴露 app/__env 等共享服务对象。
- 部分错误包含模板路径及展开后的行号；目前不提供包含/布局的完整 source map。
- CMS 完整协议维护在聚合工作区 `ptadmin-docs/reference/cms-directives.md`，本文件仅维护通用运行时与包内接入说明。


## 字段格式化接入

`Parsing/FieldOutputParser` 负责带引号的字段边界和受限参数解析，`Runtime/FieldFormatter` 提供 default、limit/pl、format_date、format_before、format_money 与固定 func 白名单；中文金额使用十进制字符串进位。既有 `|default=` 保留，并兼容旧 `|默认文字`。具体协议统一维护在聚合工作区 `ptadmin-docs/reference/template-field-format.md`。

- 保留旧参数名及格式化顺序，默认值不覆盖 0/false；limit 沿用 Str::limit 显示宽度。
- func 仅允许 trim/strip_tags，不进行动态函数调用。日期/相对时间/金额互斥，非法参数或值报错。
- 日期按应用时区显示，过去与未来时间分别输出前/后；金额字符串不经过浮点转换。
- 文本格式化移除 SafeHtml 标记，最终必须按普通文字转义；仅 default 回退时按实际值判断安全声明。
- 字段默认文字中的布局/条件/指令文本不执行；转义示例与返回数据不再次解释为源码。主题只额外接受日期值对象，不暴露 Laravel 服务对象。
- 回归入口：Core FieldFormattingTest，Dev CmsTemplateDirectivesTest 的实际 CMS 格式化页面；独立宿主 Blade 不进入该解释器。
