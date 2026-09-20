<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ControlStructureBracesTest extends TestCase
{
    public function testPhpControlStructuresUseBraces(): void
    {
        $violations = [];

        foreach ($this->phpFiles() as $path) {
            foreach ($this->findViolations($path) as $violation) {
                $violations[] = $violation;
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $paths = [];

        foreach (['config', 'database', 'routes', 'src', 'tests'] as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $paths[] = $file->getPathname();
                }
            }
        }

        sort($paths);

        return $paths;
    }

    /** @return list<string> */
    private function findViolations(string $path): array
    {
        $source = file_get_contents($path);
        self::assertIsString($source);

        $tokens = token_get_all($source);
        $violations = [];
        $conditionalTokens = [T_IF, T_ELSEIF, T_FOR, T_FOREACH, T_WHILE];

        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }

            [$type, $text, $line] = $token;
            if (in_array($type, $conditionalTokens, true)) {
                $previousToken = $this->previousSignificantToken($tokens, $index);
                if (is_array($previousToken) && in_array($previousToken[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                    continue;
                }

                $bodyToken = $this->tokenAfterCondition($tokens, $index);
                if ($type === T_WHILE && $bodyToken === ';' && $this->isDoWhileTerminator($tokens, $index)) {
                    continue;
                }
                if ($bodyToken !== '{') {
                    $violations[] = $this->violation($path, $line, trim($text));
                }
            } elseif (in_array($type, [T_ELSE, T_DO], true) && $this->nextSignificantToken($tokens, $index) !== '{') {
                $violations[] = $this->violation($path, $line, trim($text));
            }
        }

        return $violations;
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private function tokenAfterCondition(array $tokens, int $keywordIndex): array|string|null
    {
        $openIndex = $this->nextSignificantIndex($tokens, $keywordIndex);
        if ($openIndex === null || $tokens[$openIndex] !== '(') {
            return null;
        }

        $depth = 0;
        for ($index = $openIndex, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index] === '(') {
                $depth++;
            } elseif ($tokens[$index] === ')' && --$depth === 0) {
                return $this->nextSignificantToken($tokens, $index);
            }
        }

        return null;
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private function previousSignificantToken(array $tokens, int $index): array|string|null
    {
        $previousIndex = $this->previousSignificantIndex($tokens, $index);

        return $previousIndex === null ? null : $tokens[$previousIndex];
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private function isDoWhileTerminator(array $tokens, int $whileIndex): bool
    {
        $closeIndex = $this->previousSignificantIndex($tokens, $whileIndex);
        if ($closeIndex === null || $tokens[$closeIndex] !== '}') {
            return false;
        }

        $depth = 0;
        for ($index = $closeIndex; $index >= 0; $index--) {
            if ($tokens[$index] === '}') {
                $depth++;
            } elseif ($tokens[$index] === '{' && --$depth === 0) {
                $doIndex = $this->previousSignificantIndex($tokens, $index);

                return $doIndex !== null && is_array($tokens[$doIndex]) && $tokens[$doIndex][0] === T_DO;
            }
        }

        return false;
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private function previousSignificantIndex(array $tokens, int $index): ?int
    {
        for ($index--; $index >= 0; $index--) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $index;
        }

        return null;
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private function nextSignificantToken(array $tokens, int $index): array|string|null
    {
        $nextIndex = $this->nextSignificantIndex($tokens, $index);

        return $nextIndex === null ? null : $tokens[$nextIndex];
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private function nextSignificantIndex(array $tokens, int $index): ?int
    {
        for ($index++, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $index;
        }

        return null;
    }

    private function violation(string $path, int $line, string $keyword): string
    {
        return sprintf('%s:%d %s 必须使用花括号', $path, $line, $keyword);
    }
}
