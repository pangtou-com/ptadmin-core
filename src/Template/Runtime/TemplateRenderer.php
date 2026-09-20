<?php

declare(strict_types=1);

namespace PTAdmin\Template\Runtime;

use InvalidArgumentException;
use PTAdmin\Template\Directive\DirectiveDefinition;
use PTAdmin\Template\Directive\DirectiveRegistry;
use PTAdmin\Template\Parsing\DirectiveArgumentParser;

final class TemplateRenderer
{
    /** @var array<string, mixed> */
    private array $renderState = [];

    public function __construct(private DirectiveRegistry $registry, private ?DirectiveArgumentParser $arguments = null)
    {
        $this->arguments ??= new DirectiveArgumentParser();
    }

    /** @param array<string,mixed> $context */
    public function render(string $template, array $context = []): string
    {
        $this->renderState = [];

        return $this->renderFragment($template, $context, []);
    }

    private function renderFragment(string $template, array $context, array $loopStack): string
    {
        $template = $this->renderLoops($template, $context, $loopStack);
        $template = preg_replace_callback('/\{:\s*\$([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)\s*\}/', fn (array $m): string => $this->stringify($this->readPath($context, explode('.', $m[1])), false), $template) ?? $template;
        $template = preg_replace_callback('/\{\$([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*))(?:\|default=("[^"]*"|\'[^\']*\'|\$[A-Za-z_][A-Za-z0-9_.]*))?\}/', function (array $m) use ($context): string {
            $value = $this->readPath($context, explode('.', $m[1]));
            if ($value === null || $value === '') {
                $default = $m[2] ?? '';
                $value = $default !== '' && $default[0] === '$'
                    ? $this->readPath($context, explode('.', substr($default, 1)))
                    : ($default === '' ? '' : stripcslashes(substr($default, 1, -1)));
            }

            return $this->stringify($value, true);
        }, $template) ?? $template;

        return $this->renderOutputDirectives($template, $context);
    }

    private function renderLoops(string $template, array $context, array $loopStack): string
    {
        while (true) {
            $tokens = $this->directiveTokens($template);
            $match = null;
            $definition = null;
            foreach ($tokens as $token) {
                $candidate = $this->registry->get($token['name']);
                if ($candidate?->type() === 'loop') {
                    $match = $token;
                    $definition = $candidate;
                    break;
                }
            }
            if ($match === null || $definition === null) {
                break;
            }
            $start = $match['start'];
            $openEnd = $match['end'];
            $end = $this->findEnd($template, $openEnd);
            if ($end === null) {
                throw new InvalidArgumentException(sprintf('Unclosed template loop directive "%s".', $definition->fullName()));
            }
            $inner = substr($template, $openEnd, $end['start'] - $openEnd);
            [$body, $empty] = $this->splitEmptyBranch($inner);
            $arguments = $this->arguments->evaluate($match['arguments'], $context);
            $this->validateArguments($definition, $arguments, true);
            $items = $definition->invoke($arguments, ['context' => $context, 'loop' => $loopStack]);
            $items = is_iterable($items) ? $items : [];
            $variable = (string) ($arguments['as'] ?? 'field');
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $variable)) {
                throw new InvalidArgumentException('Loop parameter "as" must be a simple variable name.');
            }
            $rendered = '';
            foreach ($items as $item) {
                $childContext = array_replace($context, [$variable => $item, 'current_item' => $item]);
                $rendered .= $this->renderFragment($body, $childContext, array_merge($loopStack, [$item]));
            }
            if ($rendered === '') {
                $rendered = $this->renderFragment($empty, $context, $loopStack);
            }
            $template = substr($template, 0, $start).$rendered.substr($template, $end['end']);
        }
        return $template;
    }

    /** @return array{start:int,end:int}|null */
    private function findEnd(string $template, int $offset): ?array
    {
        $depth = 1;
        foreach ($this->directiveTokens($template, $offset) as $token) {
            $name = $token['name'];
            if ($name === 'end') {
                if (--$depth === 0) {
                    return ['start' => $token['start'], 'end' => $token['end']];
                }
                continue;
            }
            if ($name === 'empty') {
                continue;
            }
            if ($this->registry->get($name)?->type() === 'loop') {
                $depth++;
            }
        }
        return null;
    }

    /** @return array{0:string,1:string} */
    private function splitEmptyBranch(string $inner): array
    {
        $depth = 0;
        foreach ($this->directiveTokens($inner) as $token) {
            $name = $token['name'];
            if ($name === 'empty' && $depth === 0) {
                return [substr($inner, 0, $token['start']), substr($inner, $token['end'])];
            }
            if ($name === 'end') {
                $depth = max(0, $depth - 1);
            } elseif ($name !== 'empty' && $this->registry->get($name)?->type() === 'loop') {
                $depth++;
            }
        }
        return [$inner, ''];
    }

    private function invoke(DirectiveDefinition $definition, array $arguments, array $context): string
    {
        $this->validateArguments($definition, $arguments);
        $context['__ptadmin_render_state'] =& $this->renderState;
        $value = $definition->invoke($arguments, $context);
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $definition->isSafeHtml()
                ? $value
                : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $definition->isSafeHtml()
            ? (string) $encoded
            : htmlspecialchars((string) $encoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    private function validateArguments(DirectiveDefinition $definition, array &$arguments, bool $allowAs = false): void
    {
        foreach ($arguments as $name => $_value) {
            if (!array_key_exists($name, $definition->parameters()) && !($allowAs && $name === 'as')) {
                throw new InvalidArgumentException(sprintf('Template directive "%s" has unknown parameter "%s".', $definition->fullName(), $name));
            }
        }
        foreach ($definition->parameters() as $name => $parameter) {
            if (!array_key_exists($name, $arguments)) {
                if ($parameter->required()) {
                    throw new InvalidArgumentException(sprintf('Template directive "%s" is missing required parameter "%s".', $definition->fullName(), $name));
                }
                if ($parameter->hasDefault()) {
                    $arguments[$name] = $parameter->default();
                }
            }
            if (array_key_exists($name, $arguments)) {
                try {
                    $arguments[$name] = $parameter->validate($arguments[$name]);
                } catch (InvalidArgumentException $exception) {
                    throw new InvalidArgumentException(sprintf('Template directive "%s" parameter "%s": %s', $definition->fullName(), $name, $exception->getMessage()), 0, $exception);
                }
            }
        }
    }

    /** @return list<array{name:string,arguments:string,start:int,end:int}> */
    private function directiveTokens(string $template, int $offset = 0): array
    {
        $tokens = [];
        for ($index = $offset, $length = strlen($template); $index < $length - 4; $index++) {
            if (substr($template, $index, 4) !== '@pt:') {
                continue;
            }
            $nameStart = $index + 4;
            if (!isset($template[$nameStart]) || !preg_match('/[a-zA-Z]/', $template[$nameStart])) {
                continue;
            }
            $nameEnd = $nameStart + 1;
            while ($nameEnd < $length && preg_match('/[a-zA-Z0-9_-]/', $template[$nameEnd])) {
                $nameEnd++;
            }
            $name = substr($template, $nameStart, $nameEnd - $nameStart);
            $end = $nameEnd;
            $arguments = '';
            if (($template[$nameEnd] ?? '') === '(') {
                $depth = 1;
                $quote = null;
                for ($cursor = $nameEnd + 1; $cursor < $length; $cursor++) {
                    $char = $template[$cursor];
                    if ($quote !== null) {
                        if ($char === $quote && !$this->isEscaped($template, $cursor)) {
                            $quote = null;
                        }
                        continue;
                    }
                    if ($char === '"' || $char === "'") {
                        $quote = $char;
                        continue;
                    }
                    if ($char === '(') {
                        $depth++;
                    } elseif ($char === ')' && --$depth === 0) {
                        $arguments = substr($template, $nameEnd + 1, $cursor - $nameEnd - 1);
                        $end = $cursor + 1;
                        break;
                    }
                }
                if ($end === $nameEnd) {
                    throw new InvalidArgumentException(sprintf('Template directive "%s" has unbalanced parentheses.', $name));
                }
            }
            $tokens[] = ['name' => $name, 'arguments' => $arguments, 'start' => $index, 'end' => $end];
            $index = $end - 1;
        }

        return $tokens;
    }

    private function renderOutputDirectives(string $template, array $context): string
    {
        $tokens = $this->directiveTokens($template);
        if ($tokens === []) {
            return $template;
        }
        $output = '';
        $cursor = 0;
        foreach ($tokens as $token) {
            $output .= substr($template, $cursor, $token['start'] - $cursor);
            if (in_array($token['name'], ['empty', 'end'], true)) {
                throw new InvalidArgumentException('Unexpected template structure directive: '.$token['name']);
            }
            $definition = $this->registry->get($token['name']);
            if ($definition === null) {
                throw new InvalidArgumentException('Unknown template directive: '.$token['name']);
            }
            if ($definition->type() === 'loop') {
                throw new InvalidArgumentException('Unexpected template structure directive: '.$token['name']);
            }
            $arguments = $this->arguments->evaluate($token['arguments'], $context);
            $output .= $this->invoke($definition, $arguments, $context);
            $cursor = $token['end'];
        }

        return $output.substr($template, $cursor);
    }

    private function isEscaped(string $value, int $index): bool
    {
        $slashes = 0;
        for ($index--; $index >= 0 && $value[$index] === '\\'; $index--) {
            $slashes++;
        }

        return $slashes % 2 === 1;
    }
    private function readPath(array $context, array $path): mixed
    {
        $value = $context[array_shift($path)] ?? null;
        foreach ($path as $segment) {
            $value = is_array($value) ? ($value[$segment] ?? null) : (is_object($value) && isset($value->{$segment}) ? $value->{$segment} : null);
        }
        return $value;
    }
    private function stringify(mixed $value, bool $escape): string
    {
        $string = is_scalar($value) ? (string) $value : '';
        return $escape ? htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $string;
    }
}
