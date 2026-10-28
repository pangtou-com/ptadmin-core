<?php

declare(strict_types=1);

namespace PTAdmin\Template\Directive;

use InvalidArgumentException;
use RuntimeException;

final class DirectiveRegistration
{
    /** @var list<DirectiveDefinition> */
    private array $definitions = [];

    private bool $committed = false;

    public function __construct(
        private readonly DirectiveRegistry $registry,
        private readonly string            $namespace,
    ) {}

    public function define(string $name): DirectiveBuilder
    {
        $this->assertPending();

        return new DirectiveBuilder($this, $name);
    }

    /**
     * 数组与链式入口共用定义对象；整次转换成功后才加入当前注册事务。
     *
     * @param array<array-key, mixed> $declarations 入口校验短名称及声明数组，不能静默接收非法结构
     */
    public function registerMany(array $declarations): void
    {
        $this->assertPending();
        $definitions = [];
        foreach ($declarations as $name => $declaration) {
            if (!is_string($name) || !is_array($declaration)) {
                throw new InvalidArgumentException('模板指令声明必须以短名称为键、声明数组为值。');
            }
            $definitions[] = DirectiveDefinition::fromArray($name, $this->namespace, $declaration);
        }
        array_push($this->definitions, ...$definitions);
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function add(DirectiveDefinition $definition): void
    {
        $this->assertPending();
        if ($definition->namespace() !== $this->namespace) {
            throw new RuntimeException('Template directive registration namespace mismatch.');
        }

        $this->definitions[] = $definition;
    }

    public function commit(): void
    {
        $this->assertPending();
        $this->registry->commitBatch($this->definitions);
        $this->committed = true;
    }

    private function assertPending(): void
    {
        if ($this->committed) {
            throw new RuntimeException('Template directive registration is already committed.');
        }
    }
}
