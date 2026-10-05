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
        // 状态属于一次渲染，嵌套调用也不能清空外层分页或诊断状态。
        $previous = $this->renderState;
        $this->renderState = [];
        try {
            $tokens = $this->directiveTokens($template);
            $index = 0;
            $offset = 0;
            $nodes = $this->parseNodes($template, $tokens, $index, $offset);
            return $this->renderNodes($nodes, $context, []);
        } finally {
            $this->renderState = $previous;
        }
    }

    /** 先解析全部结构，再执行；空分支中的错误也不能被静默忽略。 */
    private function parseNodes(string $template, array $tokens, int &$index, int &$offset, bool $nested = false): array
    {
        $nodes = [];
        while (isset($tokens[$index])) {
            $token = $tokens[$index];
            $nodes[] = ['text' => substr($template, $offset, $token['start'] - $offset)];
            $offset = $token['start'];
            if (in_array($token['name'], ['empty', 'end', '__elseif', '__else', '__endif'], true)) {
                if (!$nested) {
                    throw new InvalidArgumentException('Unexpected template structure directive: '.$token['name']);
                }
                return $nodes;
            }
            if ($token['name'] === '__if') {
                $branches = [];
                do {
                    $condition = $tokens[$index]['arguments'];
                    (new \PTAdmin\Template\Parsing\ConditionExpression())->evaluate($condition, []);
                    $offset = $tokens[$index++]['end'];
                    $branches[] = ['condition' => $condition, 'body' => $this->parseNodes($template, $tokens, $index, $offset, true)];
                } while (($tokens[$index]['name'] ?? '') === '__elseif');
                $otherwise = [];
                if (($tokens[$index]['name'] ?? '') === '__else') {
                    $offset = $tokens[$index++]['end'];
                    $otherwise = $this->parseNodes($template, $tokens, $index, $offset, true);
                }
                if (($tokens[$index]['name'] ?? '') !== '__endif') {
                    throw new InvalidArgumentException('Unclosed template @if condition.');
                }
                $offset = $tokens[$index++]['end'];
                $nodes[] = ['branches' => $branches, 'otherwise' => $otherwise];
                continue;
            }
            $definition = $this->registry->get($token['name']);
            if ($definition === null) {
                throw new InvalidArgumentException('第 '.(substr_count(substr($template, 0, $token['start']), "\n") + 1).' 行：Unknown template directive: '.$token['name']);
            }
            foreach ($this->arguments->parse($token['arguments']) as $name => $argument) {
                if (!isset($definition->parameters()[$name]) && !($definition->type() === 'loop' && in_array($name, ['as', 'id'], true))) {
                    throw new InvalidArgumentException('Template directive "'.$definition->fullName().'" has unknown parameter "'.$name.'".');
                }
            }
            $node = ['definition' => $definition, 'arguments' => $token['arguments'], 'line' => substr_count(substr($template, 0, $token['start']), "\n") + 1];
            $offset = $token['end'];
            $index++;
            if ($definition->type() === 'loop') {
                $node['body'] = $this->parseNodes($template, $tokens, $index, $offset, true);
                $node['empty'] = [];
                if (($tokens[$index]['name'] ?? '') === 'empty') {
                    $offset = $tokens[$index++]['end'];
                    $node['empty'] = $this->parseNodes($template, $tokens, $index, $offset, true);
                }
                if (($tokens[$index]['name'] ?? '') !== 'end') {
                    throw new InvalidArgumentException('Unclosed template loop directive: '.$definition->fullName());
                }
                $offset = $tokens[$index++]['end'];
            }
            $nodes[] = $node;
        }
        $nodes[] = ['text' => substr($template, $offset)];
        $offset = strlen($template);
        return $nodes;
    }

    private function renderNodes(array $nodes, array $context, array $loopStack): string
    {
        $output = '';
        foreach ($nodes as $node) {
            if (array_key_exists('text', $node)) {
                $output .= $this->renderText($node['text'], $context);
                continue;
            }
            if (isset($node['branches'])) {
                $selected = $node['otherwise'];
                foreach ($node['branches'] as $branch) {
                    if ((new \PTAdmin\Template\Parsing\ConditionExpression())->evaluate($branch['condition'], $context)) {
                        $selected = $branch['body'];
                        break;
                    }
                }
                $output .= $this->renderNodes($selected, $context, $loopStack);
                continue;
            }
            $definition = $node['definition'];
            try {
                $arguments = $this->arguments->evaluate($node['arguments'], $context);
                if ($definition->type() !== 'loop') {
                    $this->validateArguments($definition, $arguments);
                }
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException('第 '.$node['line'].' 行：'.$exception->getMessage(), 0, $exception);
            }
            if ($definition->type() !== 'loop') {
                $output .= $this->invoke($definition, $arguments, $context);
                continue;
            }
            if (array_key_exists('id', $arguments) && !isset($definition->parameters()['id'])) {
                if (isset($arguments['as']) && $arguments['as'] !== $arguments['id']) {
                    throw new InvalidArgumentException('Loop parameters "as" and "id" must name the same variable.');
                }
                $arguments['as'] = $arguments['id'];
                unset($arguments['id']);
            }
            try {
                $this->validateArguments($definition, $arguments, true);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException('第 '.$node['line'].' 行：'.$exception->getMessage(), 0, $exception);
            }
            $variable = array_key_exists('as', $arguments) ? $arguments['as'] : 'field';
            if (!is_string($variable) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $variable)
                || in_array($variable, ['__ptadmin_render_state', 'current_item'], true)) {
                throw new InvalidArgumentException('Loop parameter "as" must be a simple, non-reserved variable name.');
            }
            $context['__ptadmin_render_state'] =& $this->renderState;
            $callContext = ['context' => $context, 'loop' => $loopStack];
            $items = $definition->invoke($arguments, $callContext);
            if (!is_iterable($items)) {
                throw new InvalidArgumentException('Loop directive must return iterable data: '.$definition->fullName());
            }
            $count = 0;
            foreach ($items as $item) {
                $count++;
                $child = array_replace($context, [$variable => $item, 'current_item' => $item]);
                $output .= $this->renderNodes($node['body'], $child, [...$loopStack, $item]);
            }
            if ($count === 0) {
                $output .= $node['empty'] !== []
                    ? $this->renderNodes($node['empty'], $context, $loopStack)
                    : $this->stringify($arguments['empty'] ?? '', true);
            }
        }
        return $output;
    }

    /** 数据只替换一次，不将内容中的变量或指令重新作为源码解释。 */
    private function renderText(string $text, array $context): string
    {
        $text = str_replace('@@pt:', '@pt:', $text);
        $output = '';
        $offset = 0;
        $parser = new \PTAdmin\Template\Parsing\FieldOutputParser();
        $formatter = new FieldFormatter();
        foreach ($parser->tokens($text) as $token) {
            $output .= substr($text, $offset, $token['start'] - $offset);
            $offset = $token['end'];
            if ($token['escaped']) {
                $output .= substr($text, $token['start'] + 1, $token['end'] - $token['start'] - 1);
                continue;
            }
            try {
                $options = $this->arguments->evaluate($token['arguments'], $context);
                $value = $formatter->format($this->readPath($context, explode('.', $token['path'])), $options, $token['blade']);
                if ($token['raw'] && $value !== null && $value !== '' && !$value instanceof SafeHtml) {
                    throw new InvalidArgumentException('原始 HTML 字段未声明安全输出。');
                }
                $output .= $this->stringify($value, !$token['raw']);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException('模板字段 '.$token['path'].'：'.$exception->getMessage(), 0, $exception);
            }
        }
        return $output.substr($text, $offset);
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
        $fields = [];
        foreach ((new \PTAdmin\Template\Parsing\FieldOutputParser())->tokens($template) as $field) {
            $fields[$field['start']] = $field['end'];
        }
        for ($index = $offset, $length = strlen($template); $index < $length; $index++) {
            if (isset($fields[$index])) {
                $index = $fields[$index] - 1;
                continue;
            }
            if (($template[$index - 1] ?? '') === '@') {
                continue;
            }
            if (substr($template, $index, 4) === '@pt:') {
                $nameStart = $index + 4;
                if (!isset($template[$nameStart]) || !preg_match('/[a-zA-Z]/', $template[$nameStart])) {
                    continue;
                }
                $nameEnd = $nameStart + 1;
                while ($nameEnd < $length && preg_match('/[a-zA-Z0-9_-]/', $template[$nameEnd])) {
                    $nameEnd++;
                }
                $name = substr($template, $nameStart, $nameEnd - $nameStart);
            } elseif (preg_match('/\G@(if|elseif|else|endif)\b/', $template, $match, 0, $index)) {
                $name = '__'.$match[1];
                $nameEnd = $index + strlen($match[0]);
            } else {
                continue;
            }
            $end = $nameEnd;
            $arguments = '';
            while (isset($template[$nameEnd]) && in_array($template[$nameEnd], [' ', "\t"], true)) {
                $nameEnd++;
            }
            if (($template[$nameEnd] ?? '') !== '(') {
                $nameEnd = $end;
            }
            if (($template[$nameEnd] ?? '') === '(') {
                $end = $nameEnd;
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
        $string = is_scalar($value) || $value instanceof SafeHtml ? (string) $value : '';
        return $escape ? htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $string;
    }
}
