<?php

declare(strict_types=1);

namespace PTAdmin\Template\Parsing;

use InvalidArgumentException;

/** Blade 条件外观下的受限表达式：只读取变量和字面量，不编译 PHP。 */
final class ConditionExpression
{
    public function evaluate(string $source, array $context): bool
    {
        $tokens = [];
        $offset = 0;
        $pattern = '~\G\s*(\$[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|-?[0-9]+(?:\.[0-9]+)?|true\b|false\b|null\b|===|!==|==|!=|>=|<=|&&|\|\||[!><()])~';
        while ($offset < strlen($source) && trim(substr($source, $offset)) !== '') {
            if (!preg_match($pattern, $source, $match, 0, $offset)) {
                throw new InvalidArgumentException('模板条件仅允许变量、字面量、比较和布尔运算：'.$source);
            }
            $tokens[] = $match[1];
            $offset += strlen($match[0]);
        }
        $index = 0;
        $value = $this->expression($tokens, $index, $context);
        if ($index !== count($tokens)) {
            throw new InvalidArgumentException('模板条件表达式未完整解析：'.$source);
        }
        return (bool) $value;
    }

    private function expression(array $tokens, int &$index, array $context, int $minimum = 0): mixed
    {
        $token = $tokens[$index++] ?? null;
        if ($token === '!') {
            $left = !$this->expression($tokens, $index, $context, 4);
        } elseif ($token === '(') {
            $left = $this->expression($tokens, $index, $context);
            if (($tokens[$index++] ?? null) !== ')') {
                throw new InvalidArgumentException('模板条件缺少右括号。');
            }
        } elseif ($token !== null && preg_match('/^(?:\$|["\']|-?[0-9]|true$|false$|null$)/', $token)) {
            $left = (new DirectiveArgumentParser())->evaluate('value='.$token, $context)['value'];
        } else {
            throw new InvalidArgumentException('模板条件缺少操作数。');
        }
        $precedence = ['||' => 1, '&&' => 2, '==' => 3, '!=' => 3, '===' => 3, '!==' => 3, '>' => 3, '<' => 3, '>=' => 3, '<=' => 3];
        while (isset($tokens[$index], $precedence[$tokens[$index]]) && $precedence[$tokens[$index]] >= $minimum) {
            $operator = $tokens[$index++];
            $right = $this->expression($tokens, $index, $context, $precedence[$operator] + 1);
            $left = match ($operator) {
                '||' => $left || $right, '&&' => $left && $right,
                '==' => $left == $right, '!=' => $left != $right, '===' => $left === $right, '!==' => $left !== $right,
                '>' => $left > $right, '<' => $left < $right, '>=' => $left >= $right, '<=' => $left <= $right,
                default => throw new InvalidArgumentException('未知条件运算符。'),
            };
        }
        return $left;
    }
}
