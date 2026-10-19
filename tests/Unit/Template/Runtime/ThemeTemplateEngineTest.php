<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Template\Runtime;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Theme\ThemeViewLoader;
use PTAdmin\Core\Theme\ThemeDescriptor;
use PTAdmin\Template\Directive\DirectiveRegistry;
use PTAdmin\Template\Runtime\TemplateRenderer;
use PTAdmin\Template\Runtime\ThemeTemplateEngine;

final class ThemeTemplateEngineTest extends TestCase
{
    private string $root;
    private Filesystem $files;
    private ThemeTemplateEngine $engine;
    private ThemeViewLoader $themes;
    private Factory $viewFactory;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-theme-engine-'.bin2hex(random_bytes(6));
        $this->files = new Filesystem();
        $this->files->makeDirectory($this->root.'/compiled', 0700, true);
        $finder = new FileViewFinder($this->files, [$this->root]);
        $compiler = new BladeCompiler($this->files, $this->root.'/compiled');
        $resolver = new EngineResolver();
        $resolver->register('blade', fn () => new CompilerEngine($compiler, $this->files));
        $this->viewFactory = new Factory($resolver, $finder, new Dispatcher(new Container()));
        $this->themes = new ThemeViewLoader($finder);
        $this->engine = new ThemeTemplateEngine(
            new CompilerEngine($compiler, $this->files),
            $finder,
            $this->themes,
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

    public function testActiveThemeKeepsNativeBladeSyntaxAndObjectData(): void
    {
        $this->themes->load(new ThemeDescriptor('test', '1.0.0', $this->root, []));
        $view = $this->root.'/native.blade.php';
        $this->files->put($view, '@foreach($items as $item)<strong>{{ $item->title }}</strong>@endforeach @php($label = "完成"){{ $label }}');

        $items = [(object) ['title' => '第一项'], (object) ['title' => '第二项']];
        self::assertSame('<strong>第一项</strong><strong>第二项</strong> 完成', $this->engine->get($view, [...compact('items'), '__env' => $this->viewFactory]));
    }

    public function testActiveThemeSupportsNativeBladeControlFlowAndPtFieldsTogether(): void
    {
        $this->themes->load(new ThemeDescriptor('test', '1.0.0', $this->root, []));
        $view = $this->root.'/mixed.blade.php';
        $this->files->put($view, '@forelse($items as $item)<span>{{ $item->title }}</span>@empty<p>暂无</p>@endforelse @if($show)<em>{$label}</em>@endif');

        $items = [(object) ['title' => '第一项']];
        self::assertSame('<span>第一项</span> <em>完成</em>', $this->engine->get($view, ['items' => $items, 'show' => true, 'label' => '完成', '__env' => $this->viewFactory]));
    }
}
