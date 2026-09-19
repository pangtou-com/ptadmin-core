<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use InvalidArgumentException;

final class AddonTemplateRuntime
{
    /** @var array<string, mixed> */
    private array $renderState = [];

    public function __construct(private AddonTemplateDirectiveRegistry $registry, private ?TemplateDirectiveArgumentParser $arguments = null) { $this->arguments ??= new TemplateDirectiveArgumentParser(); }

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
        $template = preg_replace_callback('/\{\$([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*))(?:\|default=("[^"]*"|\'[^\']*\'))?\}/', function (array $m) use ($context): string { $value = $this->readPath($context, explode('.', $m[1])); if ($value === null || $value === '') $value = isset($m[2]) ? stripcslashes(substr($m[2], 1, -1)) : ''; return $this->stringify($value, true); }, $template) ?? $template;
        return preg_replace_callback('/@pt:([a-z][a-z0-9_]*)(?:\((.*?)\))?/', function (array $m) use ($context): string { $definition = $this->registry->get($m[1]); if ($definition === null) throw new InvalidArgumentException('Unknown template directive: '.$m[1]); $arguments = $this->arguments->evaluate($m[2] ?? '', $context); return $this->invoke($definition, $arguments, $context); }, $template) ?? $template;
    }

    private function renderLoops(string $template, array $context, array $loopStack): string
    {
        while (true) {
            preg_match_all('/@pt:([a-z][a-z0-9_]*)(?:\((.*?)\))?/', $template, $tokens, PREG_OFFSET_CAPTURE);
            $match = null; $definition = null;
            foreach ($tokens[0] as $index => $token) { $candidate = $this->registry->get($tokens[1][$index][0]); if ($candidate?->type() === 'loop') { $match = [$token, $tokens[1][$index], $tokens[2][$index]]; $definition = $candidate; break; } }
            if ($match === null || $definition === null) break;
            $start = $match[0][1]; $openEnd = $start + strlen($match[0][0]); $end = $this->findEnd($template, $openEnd); if ($end === null) throw new InvalidArgumentException('Unclosed template loop directive.');
            $inner = substr($template, $openEnd, $end['start'] - $openEnd);
            [$body, $empty] = $this->splitEmptyBranch($inner);
            $arguments = $this->arguments->evaluate($match[2][0] ?? '', $context);
            $this->validateArguments($definition, $arguments, true);
            $items = $definition->invoke($arguments, ['context' => $context, 'loop' => $loopStack]);
            $items = is_iterable($items) ? $items : [];
            $variable = (string) ($arguments['as'] ?? 'field');
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $variable)) throw new InvalidArgumentException('Loop parameter "as" must be a simple variable name.');
            $rendered = '';
            foreach ($items as $item) {
                $childContext = array_replace($context, [$variable => $item, 'current_item' => $item]);
                $rendered .= $this->renderFragment($body, $childContext, array_merge($loopStack, [$item]));
            }
            if ($rendered === '') $rendered = $this->renderFragment($empty, $context, $loopStack);
            $template = substr($template, 0, $start).$rendered.substr($template, $end['end']);
        }
        return $template;
    }

    /** @return array{start:int,end:int}|null */
    private function findEnd(string $template, int $offset): ?array
    {
        $depth = 1;
        preg_match_all('/@pt:([a-z][a-z0-9_]*)(?:\((.*?)\))?/', $template, $matches, PREG_OFFSET_CAPTURE, $offset);
        foreach ($matches[0] as $index => $token) {
            $name = $matches[1][$index][0];
            if ($name === 'end') {
                if (--$depth === 0) return ['start' => $token[1], 'end' => $token[1] + strlen($token[0])];
                continue;
            }
            if ($name === 'empty') continue;
            if ($this->registry->get($name)?->type() === 'loop') $depth++;
        }
        return null;
    }

    /** @return array{0:string,1:string} */
    private function splitEmptyBranch(string $inner): array
    {
        $depth = 0;
        preg_match_all('/@pt:([a-z][a-z0-9_]*)(?:\((.*?)\))?/', $inner, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $index => $token) {
            $name = $matches[1][$index][0];
            if ($name === 'empty' && $depth === 0) {
                return [substr($inner, 0, $token[1]), substr($inner, $token[1] + strlen($token[0]))];
            }
            if ($name === 'end') $depth = max(0, $depth - 1);
            elseif ($name !== 'empty' && $this->registry->get($name)?->type() === 'loop') $depth++;
        }
        return [$inner, ''];
    }

    private function invoke(TemplateDirectiveDefinition $definition, array $arguments, array $context): string
    {
        $this->validateArguments($definition, $arguments);
        $context['__ptadmin_render_state'] =& $this->renderState;
        $value = $definition->invoke($arguments, $context);
        if ($value === null) return '';
        if (is_string($value)) {
            return $definition->isSafeHtml()
                ? $value
                : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if (is_scalar($value)) return (string) $value;

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $definition->isSafeHtml()
            ? (string) $encoded
            : htmlspecialchars((string) $encoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    private function validateArguments(TemplateDirectiveDefinition $definition, array &$arguments, bool $allowAs = false): void
    {
        foreach ($arguments as $name => $_value) if (!array_key_exists($name, $definition->parameters()) && !($allowAs && $name === 'as')) throw new InvalidArgumentException('Unknown template parameter: '.$name);
        foreach ($definition->parameters() as $name => $parameter) {
            if (!array_key_exists($name, $arguments)) {
                if ($parameter->required()) throw new InvalidArgumentException('Missing required template parameter: '.$name);
                if ($parameter->hasDefault()) $arguments[$name] = $parameter->default();
            }
            if (array_key_exists($name, $arguments)) $arguments[$name] = $parameter->validate($arguments[$name]);
        }
    }
    private function readPath(array $context, array $path): mixed { $value = $context[array_shift($path)] ?? null; foreach ($path as $segment) $value = is_array($value) ? ($value[$segment] ?? null) : (is_object($value) && isset($value->{$segment}) ? $value->{$segment} : null); return $value; }
    private function stringify(mixed $value, bool $escape): string { $string = is_scalar($value) ? (string) $value : ''; return $escape ? htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $string; }
}
