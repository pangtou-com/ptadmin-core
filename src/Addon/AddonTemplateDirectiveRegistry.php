<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use RuntimeException;

final class AddonTemplateDirectiveRegistry
{
    /** @var array<string,TemplateDirectiveDefinition> */
    private array $definitions = [];
    /** @var array<string,string> */
    private array $aliases = [];

    public function define(string $name): TemplateDirectiveBuilder { return new TemplateDirectiveBuilder($this, $name); }

    /** Legacy low-level API retained for callers migrating to define(). */
    public function register(string $name, object $handler, string $code): void { $this->define($name)->output($handler)->commit($code); }

    public function commit(TemplateDirectiveDefinition $definition): void
    {
        $this->commitBatch([$definition]);
    }

    /** @param list<TemplateDirectiveDefinition> $definitions */
    public function commitBatch(array $definitions): void
    {
        $names = [];
        $aliases = [];
        foreach ($definitions as $definition) {
            $name = $definition->fullName();
            if (isset($names[$name]) || isset($this->definitions[$name]) || isset($this->aliases[$name])) throw new RuntimeException(sprintf('Template directive "%s" is already registered.', $name));
            $names[$name] = true;
            $alias = $definition->alias();
            if ($alias !== null) {
                if (isset($aliases[$alias]) || isset($this->aliases[$alias]) || isset($this->definitions[$alias]) || isset($names[$alias])) throw new RuntimeException(sprintf('Template directive alias "%s" is already registered.', $alias));
                $aliases[$alias] = true;
            }
            if ($definition->addonCode() !== 'core' && (isset(self::builtinAliases()[$name]) || ($alias !== null && isset(self::builtinAliases()[$alias])))) throw new RuntimeException('Template directive name or alias is reserved: '.($alias ?? $name));
        }
        foreach ($definitions as $definition) {
            $name = $definition->fullName();
            $this->definitions[$name] = $definition;
            if (($alias = $definition->alias()) !== null) $this->aliases[$alias] = $name;
        }
    }

    /** @return array<string,TemplateDirectiveDefinition> */
    public function all(): array { return $this->definitions; }
    public function get(string $name): ?TemplateDirectiveDefinition { return $this->definitions[$name] ?? (isset($this->aliases[$name]) ? $this->definitions[$this->aliases[$name]] : null); }
    /** @return array<string,array<string,mixed>> */
    public function metadata(): array { $result = []; foreach ($this->definitions as $name => $definition) $result[$name] = $definition->toArray(); return $result; }
    /** @return array<string,string> */
    public function aliases(): array { return $this->aliases; }
    public function begin(string $addonCode): AddonTemplateDirectiveRegistration { return new AddonTemplateDirectiveRegistration($this, $addonCode); }
    /** @return array<string,bool> */
    public static function builtinAliases(): array { return array_fill_keys(['seo', 'img', 'images', 'file', 'files', 'dump'], true); }
}

final class AddonTemplateDirectiveRegistration
{
    /** @var list<TemplateDirectiveDefinition> */
    private array $definitions = [];
    public function __construct(private AddonTemplateDirectiveRegistry $registry, private string $addonCode) {}
    public function add(TemplateDirectiveDefinition $definition): void { if ($definition->addonCode() !== $this->addonCode) throw new RuntimeException('Template directive registration addon mismatch.'); $this->definitions[] = $definition; }
    public function commit(): void
    {
        $names = []; $aliases = [];
        foreach ($this->definitions as $definition) { if (isset($names[$definition->fullName()])) throw new RuntimeException('Duplicate template directive in addon: '.$definition->fullName()); $names[$definition->fullName()] = true; if (($alias = $definition->alias()) !== null) { if (isset($aliases[$alias])) throw new RuntimeException('Duplicate template directive alias in addon: '.$alias); $aliases[$alias] = true; } }
        foreach ($this->definitions as $definition) $this->registry->commit($definition);
    }
}
