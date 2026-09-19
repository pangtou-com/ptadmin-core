<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use InvalidArgumentException;

final class TemplateDirectiveDefinition
{
    /** @param array<string,TemplateDirectiveParameter> $parameters */
    public function __construct(private string $name, private string $addonCode, private string $type, private mixed $handler, private ?string $alias = null, private string $description = '', private array $parameters = [], private array $context = [], private array $cache = [], private bool $safeHtml = false)
    {
        if (!preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $name)) throw new InvalidArgumentException('Template directive name must be lowercase snake_case.');
        if (!in_array($type, ['output', 'loop'], true)) throw new InvalidArgumentException('Template directive type is invalid.');
        if ($alias !== null && !preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $alias)) throw new InvalidArgumentException('Template directive alias must be lowercase snake_case.');
        if ($type === 'loop' && isset($parameters['as'])) throw new InvalidArgumentException('The reserved loop parameter "as" cannot be declared.');
    }
    public function name(): string { return $this->name; }
    public function addonCode(): string { return $this->addonCode; }
    public function fullName(): string { return $this->addonCode.'_'.$this->name; }
    public function type(): string { return $this->type; }
    public function handler(): mixed { return $this->handler; }
    public function alias(): ?string { return $this->alias; }
    /** @return array<string,TemplateDirectiveParameter> */
    public function parameters(): array { return $this->parameters; }
    public function invoke(array $arguments, array $context = []): mixed { if (!is_callable($this->handler)) throw new InvalidArgumentException('Template directive handler is not callable.'); return ($this->handler)($arguments, $context); }
    /** @return array<string,mixed> */
    public function toArray(): array { $parameters = []; foreach ($this->parameters as $name => $parameter) $parameters[$name] = $parameter->toArray(); return ['name'=>$this->name,'full_name'=>$this->fullName(),'addon'=>$this->addonCode,'type'=>$this->type,'alias'=>$this->alias,'description'=>$this->description,'parameters'=>$parameters,'context'=>$this->context,'cache'=>$this->cache,'safe_html'=>$this->safeHtml]; }
    public function isSafeHtml(): bool { return $this->safeHtml; }
}
