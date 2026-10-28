<?php

declare(strict_types=1);

namespace PTAdmin\Template\Directive;

final class DirectiveBuilder
{
    private const NO_DEFAULT = "\0ptadmin_missing_default";

    private string $type = 'output';
    private mixed $handler = null;
    private ?string $alias = null;
    private string $description = '';
    private array $parameters = [];
    private array $context = [];
    private array $cache = [];
    private bool $safeHtml = false;
    private array $returns = [];
    private array $examples = [];

    public function __construct(private readonly DirectiveRegistration $registration, private readonly string $name)
    {
    }

    /** @param callable|array{class-string, string} $handler 输出内容的处理器 */
    public function output(callable|array $handler): self
    {
        $this->type = 'output';
        $this->handler = $handler;
        return $this;
    }

    /** @param callable|array{class-string, string} $handler 返回可迭代展示数据的处理器 */
    public function loop(callable|array $handler): self
    {
        $this->type = 'loop';
        $this->handler = $handler;
        return $this;
    }

    /** 条件块处理器返回包含严格布尔 allowed 的上下文对象。 */
    public function branch(callable|array $handler): self
    {
        $this->type = 'branch';
        $this->handler = $handler;
        return $this;
    }

    public function alias(string $alias): self
    {
        $this->alias = $alias;
        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function context(array $context): self
    {
        $this->context = $context;
        return $this;
    }

    public function cache(array $cache): self
    {
        $this->cache = $cache;
        return $this;
    }

    public function safeHtml(bool $safe = true): self
    {
        $this->safeHtml = $safe;
        return $this;
    }

    /** @param array<string, mixed> $returns 返回对象及嵌套字段的文档声明，不替代运行时授权 */
    public function returns(array $returns): self
    {
        $this->returns = $returns;
        return $this;
    }

    /** @param list<string> $examples 完整模板源码，供元数据导出和文档生成使用 */
    public function examples(array $examples): self
    {
        $this->examples = $examples;
        return $this;
    }

    public function parameter(
        string $name,
        string $type = 'string',
        string $description = '',
        bool $required = false,
        mixed $default = self::NO_DEFAULT,
        mixed $example = null,
        ?array $enum = null,
        mixed $min = null,
        mixed $max = null,
        ?string $itemType = null,
        bool $deprecated = false,
    ): self {
        $hasDefault = $default !== self::NO_DEFAULT;
        $this->parameters[$name] = new DirectiveParameter($name, $type, $required, $hasDefault ? $default : null,
            $description, $example, $enum, $min, $max, $itemType, $deprecated, $hasDefault);
        return $this;
    }

    public function build(): DirectiveDefinition
    {
        return new DirectiveDefinition($this->name, $this->registration->namespace(), $this->type, $this->handler,
            $this->alias, $this->description, $this->parameters, $this->context, $this->cache, $this->safeHtml,
            $this->returns, $this->examples);
    }

    public function commit(): void
    {
        $this->registration->add($this->build());
    }
}
