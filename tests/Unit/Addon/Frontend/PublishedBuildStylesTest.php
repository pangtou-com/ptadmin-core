<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon\Frontend;

use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Frontend\PublishedBuildStyles;

final class PublishedBuildStylesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-style-'.bin2hex(random_bytes(5));
        mkdir($this->root.'/.vite', 0700, true);
        file_put_contents($this->root.'/app.css', 'body { color: black; }');
    }

    protected function tearDown(): void
    {
        foreach (['.vite/sparq-root.json', 'app.css', 'escape.css'] as $file) {
            if (is_file($this->root.'/'.$file) || is_link($this->root.'/'.$file)) {
                unlink($this->root.'/'.$file);
            }
        }
        rmdir($this->root.'/.vite');
        rmdir($this->root);
    }

    public function testStylesUseContentHashAndSharedModuleUrlRules(): void
    {
        $reader = new PublishedBuildStyles();
        self::assertSame([], $reader->resolve($this->root, 'app.mjs', './modules/app'));
        $this->write(['app.css', 'app.css']);
        $hash = hash_file('sha256', $this->root.'/app.css');
        self::assertSame(['./modules/app/app.css?v='.$hash], $reader->resolve($this->root, 'app.mjs', './modules/app'));
        self::assertSame(['./plugins/demo/app.css?v='.$hash], $reader->resolve($this->root, 'app.mjs', './plugins/demo'));
        file_put_contents($this->root.'/app.css', 'body { color: blue; }');
        self::assertNotSame(['./modules/app/app.css?v='.$hash], $reader->resolve($this->root, 'app.mjs', './modules/app'));
    }

    public function testInvalidPathsAndMissingStylesRejectTheBuild(): void
    {
        foreach (['../app.css', '/app.css', 'https://example.com/app.css', 'a//app.css', 'missing.css'] as $path) {
            $this->write([$path]);
            try {
                (new PublishedBuildStyles())->resolve($this->root, 'app.mjs', './modules/app');
                self::fail('Invalid style must be rejected: '.$path);
            } catch (AddonException $exception) {
                self::assertContains($exception->errorCode, ['frontend_style_invalid', 'frontend_style_unavailable']);
            }
        }
    }

    public function testSymlinkCannotExposeStylesOutsidePublishedDirectory(): void
    {
        symlink(__FILE__, $this->root.'/escape.css');
        $this->write(['escape.css']);
        $this->expectException(AddonException::class);
        (new PublishedBuildStyles())->resolve($this->root, 'app.mjs', './modules/app');
    }

    public function testMalformedMetadataAndMismatchedEntryAreRejected(): void
    {
        foreach (['{', '{"schema":"sparq.frontend-build/v1","entry":"other.mjs","styles":[]}'] as $json) {
            file_put_contents($this->root.'/.vite/sparq-root.json', $json);
            try {
                (new PublishedBuildStyles())->resolve($this->root, 'app.mjs', './modules/app');
                self::fail('Invalid build must be rejected.');
            } catch (AddonException $exception) {
                self::assertSame('frontend_build_invalid', $exception->errorCode);
            }
        }
    }

    /** @param list<string> $styles */
    private function write(array $styles): void
    {
        file_put_contents($this->root.'/.vite/sparq-root.json', json_encode([
            'schema' => 'sparq.frontend-build/v1', 'entry' => 'app.mjs', 'styles' => $styles,
        ], JSON_THROW_ON_ERROR));
    }
}
