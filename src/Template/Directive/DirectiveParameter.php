<?php

declare(strict_types=1);

namespace PTAdmin\Template\Directive;

use InvalidArgumentException;

final class DirectiveParameter
{
    private const TYPES = ['string', 'integer', 'number', 'boolean', 'null', 'array', 'object', 'mixed'];

    public function __construct(private string $name, private string $type = 'string', private bool $required = false, private mixed $default = null, private string $description = '', private mixed $example = null, private ?array $enum = null, private mixed $min = null, private mixed $max = null, private ?string $itemType = null, private bool $deprecated = false, private bool $hasDefault = false)
    {
        if (!in_array($this->type, self::TYPES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown template parameter type "%s".', $this->type));
        }
        if ($this->itemType !== null && !in_array($this->itemType, self::TYPES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown template parameter item type "%s".', $this->itemType));
        }
        if ($this->itemType !== null && $this->type !== 'array') {
            throw new InvalidArgumentException('Template parameter item_type requires an array parameter.');
        }
    }
    public function name(): string
    {
        return $this->name;
    }
    public function type(): string
    {
        return $this->type;
    }
    public function required(): bool
    {
        return $this->required;
    }
    public function default(): mixed
    {
        return $this->default;
    }
    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }
    public function validate(mixed $value): mixed
    {
        if ($value === null && !$this->required) {
            return $value;
        }
        $valid = $this->matchesType($value, $this->type);
        if (!$valid) {
            throw new InvalidArgumentException(sprintf('Invalid value for template parameter "%s".', $this->name));
        }
        if ($this->itemType !== null) {
            foreach ($value as $item) {
                if (!$this->matchesType($item, $this->itemType)) {
                    throw new InvalidArgumentException(sprintf('Invalid item value for template parameter "%s".', $this->name));
                }
            }
        }
        if ($this->enum !== null && !in_array($value, $this->enum, true)) {
            throw new InvalidArgumentException(sprintf('Value for template parameter "%s" is not allowed.', $this->name));
        }
        if ($this->min !== null && $value < $this->min) {
            throw new InvalidArgumentException(sprintf('Template parameter "%s" is below the minimum.', $this->name));
        }
        if ($this->max !== null && $value > $this->max) {
            throw new InvalidArgumentException(sprintf('Template parameter "%s" is above the maximum.', $this->name));
        }
        return $value;
    }
    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value), 'integer' => is_int($value), 'number' => is_int($value) || is_float($value), 'boolean' => is_bool($value), 'null' => $value === null, 'array' => is_array($value), 'object' => is_array($value) || is_object($value), 'mixed' => true, default => false
        };
    }
    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['name'=>$this->name,'type'=>$this->type,'required'=>$this->required,'default'=>$this->default,'description'=>$this->description,'example'=>$this->example,'enum'=>$this->enum,'min'=>$this->min,'max'=>$this->max,'item_type'=>$this->itemType,'deprecated'=>$this->deprecated];
    }
}
