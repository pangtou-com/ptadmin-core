<?php

declare(strict_types=1);

namespace PTAdmin\Template\Directive;

final class DirectiveBuilder
{
    private const NO_DEFAULT = "\0ptadmin_missing_default";
    private string $type = 'output'; private mixed $handler = null; private ?string $alias = null; private string $description = ''; private array $parameters = []; private array $context = []; private array $cache = []; private bool $safeHtml = false;
    public function __construct(private DirectiveRegistration $registration, private string $name) {}
    public function output(callable $handler): self { $this->type = 'output'; $this->handler = $handler; return $this; }
    public function loop(callable $handler): self { $this->type = 'loop'; $this->handler = $handler; return $this; }
    /** 条件块处理器返回包含严格布尔 allowed 的上下文对象。 */
    public function branch(callable $handler): self { $this->type = 'branch'; $this->handler = $handler; return $this; }
    public function alias(string $alias): self { $this->alias = $alias; return $this; }
    public function description(string $description): self { $this->description = $description; return $this; }
    public function context(array $context): self { $this->context = $context; return $this; }
    public function cache(array $cache): self { $this->cache = $cache; return $this; }
    public function safeHtml(bool $safe = true): self { $this->safeHtml = $safe; return $this; }
    public function parameter(string $name, string $type = 'string', string $description = '', bool $required = false, mixed $default = self::NO_DEFAULT, mixed $example = null, ?array $enum = null, mixed $min = null, mixed $max = null, ?string $itemType = null, bool $deprecated = false): self { $hasDefault = $default !== self::NO_DEFAULT; $this->parameters[$name] = new DirectiveParameter($name, $type, $required, $hasDefault ? $default : null, $description, $example, $enum, $min, $max, $itemType, $deprecated, $hasDefault); return $this; }
    public function build(): DirectiveDefinition { return new DirectiveDefinition($this->name, $this->registration->namespace(), $this->type, $this->handler, $this->alias, $this->description, $this->parameters, $this->context, $this->cache, $this->safeHtml); }
    public function commit(): void { $this->registration->add($this->build()); }
}
