<?php

declare(strict_types=1);

namespace PTAdmin\Template\Directive;

use Illuminate\Container\Container;
use InvalidArgumentException;
use ReflectionMethod;
use TypeError;

final class DirectiveDefinition
{
    /**
     * @param array<string, DirectiveParameter> $parameters 两种注册入口共用的参数定义
     * @param array<string, mixed> $returns 返回对象的文档声明
     * @param array<array-key, mixed> $examples 构造时校验为完整模板字符串列表
     */
    public function __construct(
        private readonly string $name,
        private readonly string $namespace,
        private readonly string $type,
        private readonly mixed $handler,
        private readonly ?string $alias = null,
        private readonly string $description = '',
        private readonly array $parameters = [],
        private readonly array $context = [],
        private readonly array $cache = [],
        private readonly bool $safeHtml = false,
        private readonly array $returns = [],
        private readonly array $examples = [],
    ) {
        if (!preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $name)) {
            throw new InvalidArgumentException('Template directive name must be lowercase snake_case.');
        }
        if (!in_array($type, ['output', 'loop', 'branch'], true)) {
            throw new InvalidArgumentException('Template directive type is invalid.');
        }
        if ($alias !== null && !preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $alias)) {
            throw new InvalidArgumentException('Template directive alias must be lowercase snake_case.');
        }
        if (in_array($type, ['loop', 'branch'], true) && isset($parameters['as'])) {
            throw new InvalidArgumentException('The reserved loop parameter "as" cannot be declared.');
        }
        if (!is_callable($handler) && !$this->isContainerHandler($handler)) {
            throw new InvalidArgumentException('模板指令 '.$this->fullName().' 的处理器必须可调用或指定公开的类方法。');
        }
        if (!array_is_list($examples)) {
            throw new InvalidArgumentException('模板指令 '.$this->fullName().' 的 examples 必须是模板字符串列表。');
        }
        foreach ($examples as $example) {
            if (!is_string($example)) {
                throw new InvalidArgumentException('模板指令 '.$this->fullName().' 的 examples 必须是模板字符串列表。');
            }
        }
    }
    /** @param array<string, mixed> $declaration 完整定义；命名空间由当前注册事务提供 */
    public static function fromArray(string $name, string $namespace, array $declaration): self
    {
        $unknown = array_diff(array_keys($declaration), ['type', 'handler', 'alias', 'description', 'parameters', 'context', 'cache', 'safe_html', 'returns', 'examples']);
        if ($unknown !== []) {
            throw new InvalidArgumentException('模板指令 '.$namespace.'_'.$name.' 包含未知声明字段：'.implode(', ', $unknown));
        }
        $options = $declaration + [
            'type' => 'output', 'handler' => null, 'alias' => null, 'description' => '', 'parameters' => [],
            'context' => [], 'cache' => [], 'safe_html' => false, 'returns' => [], 'examples' => [],
        ];
        if (!is_array($options['parameters'])) {
            throw new InvalidArgumentException('模板指令 '.$namespace.'_'.$name.' 的 parameters 必须是参数映射。');
        }
        $parameters = [];
        try {
            foreach ($options['parameters'] as $parameter => $specification) {
                if (!is_string($parameter) || !is_array($specification)) {
                    throw new InvalidArgumentException('parameters 必须以参数名为键、声明数组为值。');
                }
                $parameters[$parameter] = DirectiveParameter::fromArray($parameter, $specification);
            }
            return new self($name, $namespace, $options['type'], $options['handler'], $options['alias'],
                $options['description'], $parameters, $options['context'], $options['cache'], $options['safe_html'],
                $options['returns'], $options['examples']);
        } catch (InvalidArgumentException|TypeError $error) {
            throw new InvalidArgumentException('模板指令 '.$namespace.'_'.$name.' 声明无效：'.$error->getMessage(), 0, $error);
        }
    }

    /** 类方法延迟到执行时由容器解析，不在指令注册阶段创建业务服务。 */
    private function isContainerHandler(mixed $handler): bool
    {
        return is_array($handler) && array_is_list($handler) && count($handler) === 2
            && is_string($handler[0]) && is_string($handler[1])
            && (class_exists($handler[0]) || interface_exists($handler[0]))
            && method_exists($handler[0], $handler[1]) && (new ReflectionMethod($handler[0], $handler[1]))->isPublic();
    }

    public function name(): string
    {
        return $this->name;
    }
    public function namespace(): string
    {
        return $this->namespace;
    }
    public function fullName(): string
    {
        return $this->namespace.'_'.$this->name;
    }
    public function type(): string
    {
        return $this->type;
    }
    public function handler(): mixed
    {
        return $this->handler;
    }
    public function alias(): ?string
    {
        return $this->alias;
    }
    /** @return array<string,DirectiveParameter> */
    public function parameters(): array
    {
        return $this->parameters;
    }
    public function invoke(array $arguments, array $context = []): mixed
    {
        $handler = $this->handler;
        if (!is_callable($handler) && $this->isContainerHandler($handler)) {
            $handler = [Container::getInstance()->make($handler[0]), $handler[1]];
        }
        if (!is_callable($handler)) {
            throw new InvalidArgumentException('模板指令 '.$this->fullName().' 的处理器不可调用。');
        }
        return $handler($arguments, $context);
    }
    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $parameters = [];
        foreach ($this->parameters as $name => $parameter) {
            $parameters[$name] = $parameter->toArray();
        }
        return [
            'name' => $this->name,
            'full_name' => $this->fullName(),
            'addon' => $this->namespace,
            'type' => $this->type,
            'alias' => $this->alias,
            'description' => $this->description,
            'parameters' => $parameters,
            'context' => $this->context,
            'cache' => $this->cache,
            'safe_html' => $this->safeHtml,
            'returns' => $this->returns,
            'examples' => $this->examples,
        ];
    }
    public function isSafeHtml(): bool
    {
        return $this->safeHtml;
    }
}
