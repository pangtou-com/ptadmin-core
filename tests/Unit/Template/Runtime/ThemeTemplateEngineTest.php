<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Template\Runtime;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Theme\ThemeViewLoader;
use PTAdmin\Template\Directive\DirectiveRegistry;
use PTAdmin\Template\Runtime\TemplateRenderer;
use PTAdmin\Template\Runtime\ThemeTemplateEngine;

final class ThemeTemplateEngineTest extends TestCase
{
    private string $root;
    private Filesystem $files;
    private ThemeTemplateEngine $engine;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-theme-engine-'.bin2hex(random_bytes(6));
        $this->files = new Filesystem();
        $this->files->makeDirectory($this->root.'/compiled', 0700, true);
        $finder = new FileViewFinder($this->files, [$this->root]);
        $compiler = new BladeCompiler($this->files, $this->root.'/compiled');
        $this->engine = new ThemeTemplateEngine(
            new CompilerEngine($compiler, $this->files),
            $finder,
            new ThemeViewLoader($finder),
            new TemplateRenderer(new DirectiveRegistry()),
        );
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->root);
    }

    public function testClearingCompilationStateRecompilesChangedHostViews(): void
    {
        $view = $this->root.'/host.blade.php';
        $this->files->put($view, '旧内容 {{ $title }}');
        self::assertSame('旧内容 &lt;测试&gt;', $this->engine->get($view, ['title' => '<测试>']));

        $this->files->put($view, '新内容 {{ $title }}');
        touch($view, time() + 60);
        clearstatcache(true, $view);
        self::assertSame('旧内容 完成', $this->engine->get($view, ['title' => '完成']));

        $this->engine->forgetCompiledOrNotExpired();
        self::assertSame('新内容 完成', $this->engine->get($view, ['title' => '完成']));
    }

    public function testHostCompilerCanPrecompileBladeViews(): void
    {
        $view = $this->root.'/cached.blade.php';
        $this->files->put($view, '@if($visible){{ $title }}@endif');
        $compiler = $this->engine->getCompiler();
        $compiler->compile($view);

        self::assertFileExists($compiler->getCompiledPath($view));
        self::assertSame('已缓存', $this->engine->get($view, ['visible' => true, 'title' => '已缓存']));
        self::assertSame('', $this->engine->get($view, ['visible' => false, 'title' => '隐藏']));
    }
}
