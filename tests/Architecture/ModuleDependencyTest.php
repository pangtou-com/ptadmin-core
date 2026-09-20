<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ModuleDependencyTest extends TestCase
{
    public function testTemplateModuleDoesNotDependOnAddonModule(): void
    {
        $violations = [];

        foreach ($this->phpFiles(dirname(__DIR__, 2).'/src/Template') as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            if (str_contains($source, 'PTAdmin\\Addon\\')) {
                $violations[] = $path;
            }
        }

        self::assertSame([], $violations, 'Template 模块不能依赖 Addon 模块：'.implode(', ', $violations));
    }

    public function testAddonRootDoesNotContainPhpClasses(): void
    {
        $files = glob(dirname(__DIR__, 2).'/src/Addon/*.php') ?: [];

        self::assertSame([], $files, 'Addon 根目录不能继续平铺 PHP 类。');
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }
}
