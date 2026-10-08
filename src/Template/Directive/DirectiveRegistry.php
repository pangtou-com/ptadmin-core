<?php

declare(strict_types=1);

namespace PTAdmin\Template\Directive;

use RuntimeException;

final class DirectiveRegistry
{
    /** @var array<string,DirectiveDefinition> */
    private array $definitions = [];
    /** @var array<string,list<string>> */
    private array $aliases = [];

    /** Begin a namespace-scoped registration transaction. */
    public function begin(string $namespace): DirectiveRegistration
    {
        return new DirectiveRegistration($this, $namespace);
    }

    /** Bind the directive namespace before definitions are created. */
    public function forNamespace(string $namespace): DirectiveRegistration
    {
        return $this->begin($namespace);
    }

    /** @param list<DirectiveDefinition> $definitions */
    public function commitBatch(array $definitions): void
    {
        $names = [];
        $aliases = [];
        foreach ($definitions as $definition) {
            $name = $definition->fullName();
            if (isset($names[$name]) || isset($this->definitions[$name]) || isset($this->aliases[$name])) {
                throw new RuntimeException(sprintf('Template directive "%s" is already registered.', $name));
            }
            $names[$name] = true;
            $alias = $definition->alias();
            if ($alias !== null) {
                if (isset($aliases[$alias])) {
                    $aliases[$alias][] = $name;
                } else {
                    $aliases[$alias] = [$name];
                }
            }
            if ($definition->namespace() !== 'core' && (($alias !== null && isset(self::builtinAliases()[$alias])) || ($alias !== null && isset(self::structuralAliases()[$alias])))) {
                throw new RuntimeException('Template directive alias is reserved: '.$alias);
            }
        }
        foreach ($definitions as $definition) {
            $name = $definition->fullName();
            $this->definitions[$name] = $definition;
            if (($alias = $definition->alias()) !== null) {
                $this->aliases[$alias] = array_values(array_unique(array_merge($this->aliases[$alias] ?? [], [$name])));
            }
        }
    }

    /** @return array<string,DirectiveDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }
    public function get(string $name): ?DirectiveDefinition
    {
        if (isset($this->definitions[$name])) {
            return $this->definitions[$name];
        }
        $targets = $this->aliases[$name] ?? [];
        if (count($targets) > 1) {
            throw new RuntimeException(sprintf('Template directive alias "%s" is ambiguous; use one of: %s.', $name, implode(', ', $targets)));
        }

        return isset($targets[0]) ? $this->definitions[$targets[0]] : null;
    }
    /** @return array<string,array<string,mixed>> */
    public function metadata(): array
    {
        $result = [];
        foreach ($this->definitions as $name => $definition) {
            $result[$name] = $definition->toArray();
        }
        return $result;
    }
    /** @return array<string,list<string>> */
    public function aliases(): array
    {
        return $this->aliases;
    }
    /** @return array<string,bool> */
    public static function builtinAliases(): array
    {
        return array_fill_keys(['seo', 'img', 'images', 'file', 'files', 'dump', 'crumb', 'crumb_data', 'pager', 'pager_data'], true);
    }
    /** @return array<string,bool> */
    public static function structuralAliases(): array
    {
        return ['empty' => true, 'end' => true];
    }
}
