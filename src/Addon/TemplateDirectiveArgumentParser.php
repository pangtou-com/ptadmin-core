<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use InvalidArgumentException;

final class TemplateDirectiveArgumentParser
{
    /** @return array<string,array<string,mixed>> */
    public function parse(string $source): array
    {
        $arguments = [];
        foreach ($this->split($source) as $part) {
            if ($part === '') {
                continue;
            }
            $equals = $this->findTopLevel($part, '=');
            if ($equals === null) {
                throw new InvalidArgumentException('Template directive arguments must use name=value syntax.');
            }
            $name = trim(substr($part, 0, $equals));
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                throw new InvalidArgumentException('Template directive argument name is invalid.');
            }
            $arguments[$name] = $this->parseValue(trim(substr($part, $equals + 1)));
        }
        return $arguments;
    }

    /** @param array<string,mixed> $context */
    public function evaluate(string $source, array $context = []): array
    {
        $result = [];
        foreach ($this->parse($source) as $name => $node) {
            $result[$name] = $this->evaluateNode($node, $context);
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function parseValue(string $value): array
    {
        if ($value === '') {
            throw new InvalidArgumentException('Template directive argument value is empty.');
        }
        if (($value[0] ?? '') === '"' || ($value[0] ?? '') === "'") {
            $quote = $value[0];
            $closing = $this->findClosingQuote($value, $quote, 1);
            if ($closing === null || $closing !== strlen($value) - 1) {
                throw new InvalidArgumentException('Template directive string is unterminated.');
            }
            return ['type' => 'string', 'value' => stripcslashes(substr($value, 1, -1))];
        }
        if (preg_match('/^-?(?:0|[1-9][0-9]*)$/', $value)) {
            return ['type' => 'integer', 'value' => (int) $value];
        }
        if (preg_match('/^-?(?:[0-9]+\.[0-9]+)$/', $value)) {
            return ['type' => 'number', 'value' => (float) $value];
        }
        if ($value === 'true' || $value === 'false') {
            return ['type' => 'boolean', 'value' => $value === 'true'];
        }
        if ($value === 'null') {
            return ['type' => 'null', 'value' => null];
        }
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $value)) {
            return ['type' => 'string', 'value' => $value];
        }
        if ($value[0] === '$') {
            $path = substr($value, 1);
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*$/', $path)) {
                throw new InvalidArgumentException('Template variable reference is invalid.');
            }
            return ['type' => 'variable', 'path' => explode('.', $path)];
        }
        if ($value[0] === '[' && $value[-1] === ']') {
            $items = [];
            foreach ($this->split(substr($value, 1, -1)) as $item) {
                $item = trim($item);
                if ($item === '') {
                    continue;
                }
                [$equals, $operatorLength] = $this->findAssignment($item);
                if ($equals !== null) {
                    $key = trim(substr($item, 0, $equals));
                    if (($key[0] ?? '') === '"' || ($key[0] ?? '') === "'") {
                        $quote = $key[0];
                        $closing = $this->findClosingQuote($key, $quote, 1);
                        if ($closing === null || $closing !== strlen($key) - 1) {
                            throw new InvalidArgumentException('Template array key is invalid.');
                        }
                        $key = stripcslashes(substr($key, 1, -1));
                    } elseif (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
                        throw new InvalidArgumentException('Template array key is invalid.');
                    }
                    $items[$key] = $this->parseValue(trim(substr($item, $equals + $operatorLength)));
                } else {
                    $items[] = $this->parseValue($item);
                }
            }
            return ['type' => 'array', 'items' => $items];
        }
        throw new InvalidArgumentException('Unsupported template directive argument expression.');
    }

    private function evaluateNode(array $node, array $context): mixed
    {
        return match ($node['type']) {
            'variable' => $this->readPath($context, $node['path']),
            'array' => array_map(fn (array $item): mixed => $this->evaluateNode($item, $context), $node['items']),
            default => $node['value'],
        };
    }

    private function readPath(array $context, array $path): mixed
    {
        $value = $context[array_shift($path)] ?? null;
        foreach ($path as $segment) {
            $value = is_array($value) ? ($value[$segment] ?? null) : (is_object($value) && isset($value->{$segment}) ? $value->{$segment} : null);
        }
        return $value;
    }

    /** @return list<string> */
    private function split(string $source): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        $length = strlen($source);
        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];
            if ($quote !== null) {
                if ($char === $quote && ($i === 0 || $source[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }
            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = trim(substr($source, $start, $i - $start));
                $start = $i + 1;
            }
        }
        if ($quote !== null || $depth !== 0) {
            throw new InvalidArgumentException('Template directive argument expression is unbalanced.');
        }
        $parts[] = trim(substr($source, $start));
        return $parts;
    }

    private function findTopLevel(string $source, string $needle): ?int
    {
        $depth = 0;
        $quote = null;
        for ($i = 0, $length = strlen($source); $i < $length; $i++) {
            $char = $source[$i];
            if ($quote !== null) {
                if ($char === $quote && $source[$i - 1] !== '\\') {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }
            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;
            } elseif ($char === $needle && $depth === 0) {
                return $i;
            }
        }
        return null;
    }

    /** @return array{0:int|null,1:int} */
    private function findAssignment(string $source): array
    {
        $equals = $this->findTopLevel($source, '=');
        if ($equals === null) {
            return [null, 0];
        }

        return [$equals, ($source[$equals + 1] ?? '') === '>' ? 2 : 1];
    }

    private function findClosingQuote(string $value, string $quote, int $offset): ?int
    {
        for ($index = $offset, $length = strlen($value); $index < $length; $index++) {
            if ($value[$index] === $quote && !$this->isEscaped($value, $index)) {
                return $index;
            }
        }

        return null;
    }

    private function isEscaped(string $value, int $index): bool
    {
        $slashes = 0;
        for ($index--; $index >= 0 && $value[$index] === '\\'; $index--) {
            $slashes++;
        }

        return $slashes % 2 === 1;
    }
}
