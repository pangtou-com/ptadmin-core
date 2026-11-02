<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon\Frontend;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Frontend\FrontendPublisher;
use PTAdmin\Addon\Frontend\PublishedMenuIcons;

final class PublishedMenuIconsTest extends TestCase
{
    private string $root;
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 3h18v18H3z"/></svg>';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-icons-'.bin2hex(random_bytes(5));
        mkdir($this->root.'/source/.vite', 0700, true);
        mkdir($this->root.'/source/icons', 0700, true);
        file_put_contents($this->root.'/source/icons/article.svg', self::SVG);
        $this->declareIcons(['article' => 'icons/article.svg']);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->root);
    }

    public function testPublishUpdatesHashesRemovesOldDeclarationsAndUninstallRemovesIndex(): void
    {
        $publisher = new FrontendPublisher($this->root.'/public');
        $icons = new PublishedMenuIcons();
        $target = $this->root.'/public/admintest/plugins/demo';
        $publisher->publish('admintest', 'demo', $this->root.'/source');
        $first = $icons->resolve($target, 'demo', './plugins/demo');
        self::assertSame(['demo:article' => './plugins/demo/icons/article.svg?v='.hash('sha256', self::SVG)], $first);
        file_put_contents($this->root.'/source/icons/article.svg', str_replace('24 24', '32 32', self::SVG));
        $publisher->publish('admintest', 'demo', $this->root.'/source');
        self::assertNotSame($first, $icons->resolve($target, 'demo', './plugins/demo'));
        $this->declareIcons([]);
        file_put_contents($this->root.'/source/.ptadmin-icons.json', '{"icons":{"forged":"https://evil.example/icon.svg"}}');
        $publisher->publish('admintest', 'demo', $this->root.'/source');
        self::assertSame([], $icons->resolve($target, 'demo', './plugins/demo'));
        $publisher->remove('admintest', 'demo');
        self::assertDirectoryDoesNotExist($target);
        self::assertSame([], $icons->resolve($target, 'demo', './plugins/demo'));
    }

    public function testInvalidSvgOrPathsPreserveThePreviousPublication(): void
    {
        $publisher = new FrontendPublisher($this->root.'/public');
        $publisher->publish('admintest', 'demo', $this->root.'/source');
        $target = $this->root.'/public/admintest/plugins/demo';
        $before = file_get_contents($target.'/.ptadmin-icons.json');
        foreach ([
            '<svg',
            str_replace('<path', '<script>alert(1)</script><path', self::SVG),
            str_replace('<path', '<foreignObject/><path', self::SVG),
            str_replace('<path', '<use href="https://evil.example/a.svg"/><path', self::SVG),
            str_replace('<path', '<path onload="alert(1)"', self::SVG),
            str_replace('<path', '<path fill="url(https://evil.example/a)"', self::SVG),
            str_replace('<path', '<path style="fill:red"', self::SVG),
            '<?xml-stylesheet href="https://evil.example/a.css"?>'.self::SVG,
            '<!DOCTYPE svg [<!ENTITY secret SYSTEM "file:///etc/passwd">]>'.self::SVG,
            str_replace('<path', '<?unsafe test?><path', self::SVG),
            str_repeat(' ', 65537).self::SVG,
        ] as $invalidSvg) {
            file_put_contents($this->root.'/source/icons/article.svg', $invalidSvg);
            $this->assertRejected($publisher);
            self::assertSame(self::SVG, file_get_contents($target.'/icons/article.svg'));
            self::assertSame($before, file_get_contents($target.'/.ptadmin-icons.json'));
        }
        file_put_contents($this->root.'/source/icons/article.svg', self::SVG);
        foreach (['../article.svg', '/article.svg', 'https://evil.example/a.svg', 'icons//article.svg', 'icons/article.svg?v=1', 'missing.svg'] as $invalidPath) {
            $this->declareIcons(['article' => $invalidPath]);
            $this->assertRejected($publisher);
            self::assertSame($before, file_get_contents($target.'/.ptadmin-icons.json'));
        }
        self::assertSame([], glob($this->root.'/public/admintest/plugins/.demo.*'));
    }

    public function testSymlinksAndCrossNamespaceNamesAreRejected(): void
    {
        $publisher = new FrontendPublisher($this->root.'/public');
        $this->declareIcons(['other:article' => 'icons/article.svg']);
        $this->assertRejected($publisher);
        $this->declareIcons(['article' => 'icons/article.svg']);
        unlink($this->root.'/source/icons/article.svg');
        symlink(__FILE__, $this->root.'/source/icons/article.svg');
        $this->expectException(AddonException::class);
        $publisher->publish('admintest', 'demo', $this->root.'/source');
    }

    private function assertRejected(FrontendPublisher $publisher): void
    {
        try {
            $publisher->publish('admintest', 'demo', $this->root.'/source');
            self::fail('Invalid menu icon must reject publication.');
        } catch (AddonException $exception) {
            self::assertSame('frontend_icons_invalid', $exception->errorCode);
        }
    }

    private function declareIcons(array $icons): void
    {
        file_put_contents($this->root.'/source/.vite/ptadmin-icons.json', json_encode([
            'schema' => 'ptadmin.menu-icons/v1', 'icons' => $icons,
        ], JSON_THROW_ON_ERROR));
    }
}
