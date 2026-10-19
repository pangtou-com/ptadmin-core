<?php

declare(strict_types=1);

namespace PTAdmin\Template\Runtime;

use InvalidArgumentException;
use PTAdmin\Template\Parsing\FieldOutputParser;

/** 仅编译 PT 扩展；原生流程、布局和表达式继续交给 Blade。 */
final class ThemeBladeCompiler
{
    public function __construct(private readonly TemplateRenderer $renderer)
    {
    }

    public function compile(string $source): string
    {
        $stack = [];
        $sequence = 0;
        $prefix = substr(hash('sha256', $source), 0, 16);
        $compiled = '';
        // Blade 已保护 @php/@verbatim；原生 PHP 中的字符串同样不能被识别为指令。
        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || $token[0] !== T_INLINE_HTML) {
                $compiled .= is_array($token) ? $token[1] : $token;
                continue;
            }
            $text = $token[1];
            $offset = 0;
            while (preg_match('/@__raw_block_[0-9]+__@|@@?pt:[A-Za-z][A-Za-z0-9_-]*|@?\{\{|@?\{!!|@?\{(?::\s*)?\$|@@?[A-Za-z_][A-Za-z0-9_]*/', $text, $match, PREG_OFFSET_CAPTURE, $offset)) {
                $start = $match[0][1];
                $head = $match[0][0];
                $compiled .= substr($text, $offset, $start - $offset);
                $end = $start + strlen($head);
                if (str_starts_with($head, '@__raw_block_')) {
                    $compiled .= $head.'<?php ?>';
                    $offset = $end;
                    continue;
                }
                if (str_contains($head, '{')) {
                    $blade = str_contains($head, '{{') || str_contains($head, '{!!');
                    $close = str_contains($head, '{!!') ? '!!}' : ($blade ? '}}' : '}');
                    $end = $this->closing($text, $end, $close);
                    $field = substr($text, $start, $end - $start);
                    if ($blade) {
                        $compiled .= $field;
                    } else {
                        (new FieldOutputParser())->tokens($field);
                        $compiled .= '<?php echo $__ptadmin->bladeField('.var_export($field, true).', get_defined_vars()); ?>';
                    }
                } else {
                    $argumentStart = $end;
                    while (isset($text[$argumentStart]) && ctype_space($text[$argumentStart])) {
                        $argumentStart++;
                    }
                    $arguments = '';
                    if (($text[$argumentStart] ?? '') === '(') {
                        $end = $this->closing($text, $argumentStart + 1, ')');
                        $arguments = substr($text, $argumentStart + 1, $end - $argumentStart - 2);
                    }
                    if (str_starts_with($head, '@@pt:')) {
                        $compiled .= '<?php echo '.var_export(substr($text, $start + 1, $end - $start - 1), true).'; ?>';
                    } elseif (str_starts_with($head, '@pt:')) {
                        $compiled .= $this->directive(substr($head, 4), $arguments, $stack, $prefix.'_'.++$sequence);
                    } else {
                        // 独立 PHP 边界让紧邻文本或另一个指令的 Blade 标记也能被识别。
                        $compiled .= '<?php ?>'.substr($text, $start, $argumentStart - $start);
                        if ($end > $argumentStart) {
                            $compiled .= '('.(str_starts_with($head, '@@') ? $arguments : $this->legacyPaths($arguments, in_array($head, ['@if', '@elseif'], true))).')';
                        } else {
                            // 未带括号时不消费原指令后的空白。
                            $end = $argumentStart;
                        }
                        if (($text[$end] ?? '') === '@') {
                            $compiled .= '<?php ?>';
                        }
                    }
                }
                $offset = $end;
            }
            $compiled .= substr($text, $offset);
        }
        if ($stack !== []) {
            throw new InvalidArgumentException('Unclosed template loop directive: '.end($stack)['name']);
        }
        return $compiled;
    }

    private function directive(string $name, string $arguments, array &$stack, string $id): string
    {
        if ($name === 'empty' || $name === 'end') {
            $index = array_key_last($stack);
            if ($index === null || ($name === 'empty' && ($stack[$index]['type'] !== 'loop' || $stack[$index]['empty']))) {
                throw new InvalidArgumentException('Unexpected template structure directive: '.$name);
            }
            $block = $stack[$index];
            $frame = $block['frame'];
            if ($name === 'empty') {
                $stack[$index]['empty'] = true;
                return '<?php endforeach; if ('.$frame.'->count === 0): ?>';
            }
            array_pop($stack);
            $close = $block['type'] === 'branch' || $block['empty'] ? 'endif;' : 'endforeach; if ('.$frame.'->count === 0) { echo '.$frame.'->empty; }';
            return '<?php '.$close.' } finally { foreach ('.$frame.'->missing as $__ptadminKey) { unset($$__ptadminKey); } extract('.$frame.'->previous, EXTR_OVERWRITE); unset('.$frame.'); } ?>';
        }
        $definition = $this->renderer->bladeDefinition($name, $arguments);
        $call = var_export($name, true).', '.var_export($arguments, true).', get_defined_vars()';
        if ($definition->type() === 'output') {
            return '<?php echo $__ptadmin->bladeOutput('.$call.'); ?>';
        }
        $frame = '$__ptadminFrame_'.$id;
        $stack[] = ['name' => $name, 'type' => $definition->type(), 'frame' => $frame, 'empty' => false];
        $open = '<?php '.$frame.' = $__ptadmin->bladeBlock('.$call.'); try { ';
        if ($definition->type() === 'branch') {
            return $open.'${'.$frame.'->variable} = '.$frame.'->value; if ('.$frame.'->value["allowed"]): ?>';
        }
        return $open.'foreach ('.$frame.'->value as '.$frame.'->item): '.$frame.'->count++; ${'.$frame.'->variable} = '.$frame.'->item; $current_item = '.$frame.'->item; $__ptadminLoopStack = [...'.$frame.'->loopStack, $current_item]; ?>';
    }

    /** 识别嵌套括号和引号，避免 count(...) 或字面量中的右括号截断参数。 */
    private function closing(string $source, int $offset, string $close): int
    {
        $quote = null;
        $depth = 0;
        for ($index = $offset, $length = strlen($source); $index < $length; $index++) {
            $char = $source[$index];
            if ($quote !== null) {
                if ($char === '\\') {
                    $index++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($depth === 0 && substr($source, $index, strlen($close)) === $close) {
                return $index + strlen($close);
            } elseif ($char === ($close === ')' ? '(' : '{')) {
                $depth++;
            } elseif ($char === ($close === ')' ? ')' : '}')) {
                $depth--;
            }
        }
        throw new InvalidArgumentException('主题指令或字段缺少结束符：'.$close);
    }

    /** 只兼容紧邻变量的历史点路径，不修改 PHP 字符串和原生数组/对象表达式。 */
    private function legacyPaths(string $expression, bool $condition = false): string
    {
        // 迁入主题的旧条件将未提供的展示字段视为 null；原生 PHP 表达式不改变语义。
        if ($condition) {
            try {
                (new \PTAdmin\Template\Parsing\ConditionExpression())->evaluate($expression, []);
            } catch (InvalidArgumentException) {
                $condition = false;
            }
        }
        $tokens = token_get_all('<?php '.$expression);
        array_shift($tokens);
        $result = '';
        $quoted = false;
        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if ($token === '"' || $token === '`') {
                $quoted = !$quoted;
            } elseif (is_array($token) && $token[0] === T_START_HEREDOC) {
                $quoted = true;
            } elseif (is_array($token) && $token[0] === T_END_HEREDOC) {
                $quoted = false;
            }
            if (!$quoted && is_array($token) && $token[0] === T_VARIABLE) {
                $path = [];
                while (($tokens[$index + 1] ?? null) === '.' && is_array($tokens[$index + 2] ?? null) && $tokens[$index + 2][0] === T_STRING) {
                    $path[] = $tokens[$index + 2][1];
                    $index += 2;
                }
                if ($path !== []) {
                    $result .= '\\data_get('.$token[1].' ?? null, '.var_export(implode('.', $path), true).')';
                    continue;
                }
                if ($condition) {
                    $result .= '('.$token[1].' ?? null)';
                    continue;
                }
            }
            $result .= is_array($token) ? $token[1] : $token;
        }
        return $result;
    }
}
