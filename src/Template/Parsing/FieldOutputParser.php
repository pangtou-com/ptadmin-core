<?php

declare(strict_types=1);

namespace PTAdmin\Template\Parsing;

use InvalidArgumentException;

/** 扫描字段边界时识别引号，默认文字中的指令和右花括号不属于模板结构。 */
final class FieldOutputParser
{
    /** @return list<array{start:int,end:int,path:string,arguments:string,raw:bool,escaped:bool,blade:bool}> */
    public function tokens(string $source): array
    {
        $tokens = [];
        $offset = 0;
        while (preg_match('/@?\{(?:\{\s*|:\s*)?\$/', $source, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $match[0][1];
            $prefix = $match[0][0];
            $blade = str_contains($prefix, '{{');
            $escaped = str_starts_with($prefix, '@');
            $bodyStart = $start + strlen($prefix);
            $end = $this->closingBrace($source, $bodyStart);
            if ($blade && ($source[$end + 1] ?? '') !== '}') {
                throw new InvalidArgumentException('Blade 字段输出缺少结束括号。');
            }
            $body = trim(substr($source, $bodyStart, $end - $bodyStart));
            $offset = $end + ($blade ? 2 : 1);
            // 转义内容只要求边界闭合，不校验其示例语法。
            [$path, $arguments] = $escaped ? ['', ''] : $this->expression($body, $blade);
            $tokens[] = ['start' => $start, 'end' => $offset, 'path' => $path, 'arguments' => $arguments,
                'raw' => str_contains($prefix, ':'), 'escaped' => $escaped, 'blade' => $blade];
        }
        return $tokens;
    }

    private function closingBrace(string $source, int $offset): int
    {
        $quote = null;
        for ($index = $offset, $length = strlen($source); $index < $length; $index++) {
            $char = $source[$index];
            if ($quote !== null) {
                if ($char === '\\') {
                    $index++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '}') {
                return $index;
            }
        }
        throw new InvalidArgumentException('字段输出缺少结束花括号或字符串引号。');
    }

    /** @return array{string,string} */
    private function expression(string $body, bool $blade): array
    {
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)(.*)$/sD', $body, $match)) {
            throw new InvalidArgumentException('模板字段路径无效：'.$body);
        }
        $path = $match[1];
        $suffix = trim($match[2]);
        if ($suffix === '') {
            return [$path, ''];
        }
        if ($blade) {
            if (!str_starts_with($suffix, '??')) {
                throw new InvalidArgumentException('Blade 字段只支持变量路径及 ?? 默认值。');
            }
            $arguments = 'default='.trim(substr($suffix, 2));
        } elseif (str_starts_with($suffix, '|')) {
            $fallback = substr($suffix, 1);
            $arguments = str_starts_with($fallback, 'default=') ? $fallback : 'default='.json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } elseif (str_starts_with($suffix, '(') && str_ends_with($suffix, ')')) {
            $arguments = $this->parameters(substr($suffix, 1, -1));
        } else {
            throw new InvalidArgumentException('模板字段格式化语法无效：'.$path);
        }
        $parsed = (new DirectiveArgumentParser())->parse($arguments);
        foreach ($parsed as $name => $node) {
            if (!in_array($name, ['default', 'limit', 'pl', 'format_date', 'format_before', 'format_money', 'func'], true)) {
                throw new InvalidArgumentException('模板字段 '.$path.' 的格式化参数未知：'.$name);
            }
        }
        return [$path, $arguments];
    }

    /** 只将历史布尔短写归一化；值仍交给统一受限参数解析器。 */
    private function parameters(string $source): string
    {
        $parts = [];
        $start = 0;
        $quote = null;
        $depth = 0;
        for ($index = 0, $length = strlen($source); $index <= $length; $index++) {
            $char = $source[$index] ?? ',';
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
            } elseif ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $part = trim(substr($source, $start, $index - $start));
                if (in_array($part, ['format_before', 'format_money'], true)) {
                    $part .= '=true';
                }
                if ($part !== '') {
                    $parts[] = $part;
                }
                $start = $index + 1;
            }
        }
        if ($quote !== null || $depth !== 0) {
            throw new InvalidArgumentException('字段格式化参数未闭合。');
        }
        $names = [];
        $parser = new DirectiveArgumentParser();
        foreach ($parts as $part) {
            foreach ($parser->parse($part) as $name => $node) {
                if (isset($names[$name])) {
                    throw new InvalidArgumentException('字段格式化参数重复：'.$name);
                }
                $names[$name] = true;
            }
        }
        return implode(',', $parts);
    }
}
