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
    private DirectiveRegistry $registry;
    private int $fixtureSequence = 0;

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
            $this->themes,
            new TemplateRenderer($this->registry = new DirectiveRegistry()),
        );
        $resolver->register('blade', fn () => $this->engine);
        $registration = $this->registry->forNamespace('test');
        $registration->define('rows')->loop(static fn (array $arguments): array => $arguments['items'])
            ->parameter('items', 'array', required: true)->parameter('empty', 'string')->commit();
        $registration->define('label')->output(static fn (array $arguments): string => $arguments['value'])
            ->parameter('value', 'string', required: true)->commit();
        $registration->define('perm')->branch(static fn (array $arguments): array => ['allowed' => $arguments['allowed'], 'reason' => '拒绝'])
            ->parameter('allowed', 'boolean', required: true)->commit();
        $registration->commit();
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

    public function testBusinessLoopsExposeVariablesToBladeAndRestoreOuterScope(): void
    {
        self::assertSame('第一项:子项;外层', $this->renderTheme(
            '@pt:test_rows(items=$items,as=row)@if(count($row["children"]) > 0){{ $row["title"] }}:@foreach($row["children"] as $child){{ $child }};@endforeach@endif@pt:end{{ $row }}',
            ['items' => [['title' => '第一项', 'children' => ['子项']]], 'row' => '外层'],
        ));
    }

    public function testBladeLoopsAndPhpVariablesAreAvailableToBusinessDirectives(): void
    {
        self::assertSame('第一项第一项第二项第二项完成', $this->renderTheme(
            '@foreach($items as $row)@pt:test_label(value=$row.title){$row.title}@endforeach@php($label = "完成")@pt:test_label(value=$label)',
            ['items' => [(object) ['title' => '第一项'], (object) ['title' => '第二项']]],
        ));
    }

    public function testBladeConditionsDoNotExecuteSkippedBusinessDirectives(): void
    {
        self::assertSame('完成', $this->renderTheme('@if(false)@pt:test_label(value=$missing)@elseif(strlen("yes") > 0)完成@else错误@endif'));
    }

    public function testIncludesAndLayoutsShareBusinessLoopScope(): void
    {
        $this->files->put($this->root.'/layout.blade.php', '<main>@yield("content")</main>');
        $this->files->put($this->root.'/partial.blade.php', '{{ $label }}:@pt:test_label(value=$row.title)');
        self::assertSame('<main>标签:第一项</main>', $this->renderTheme(
            '@extends("layout")@section("content")@pt:test_rows(items=$items,as=row)@include("partial", ["label" => "标签"])@pt:end@endsection',
            ['items' => [['title' => '第一项']]],
        ));
    }

    public function testBusinessOutputIsNeverCompiledAsBladeSource(): void
    {
        $literal = '@php throw new \\RuntimeException("执行了数据"); @endphp {{ 7 * 7 }}';
        self::assertSame(htmlspecialchars($literal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $this->renderTheme('@pt:test_label(value=$label)', ['label' => $literal]));
    }

    public function testBranchesAndEmptyLoopsKeepBladeConditionsAndRestoreVariables(): void
    {
        self::assertSame('拒绝外层|空|&lt;空&gt;', $this->renderTheme(
            '@pt:test_perm(allowed=false,as=p)允许@else@if(strlen($p["reason"]) > 0){{ $p["reason"] }}@endif@pt:end{{ $p }}|@pt:test_rows(items=[])错误@pt:empty@forelse([] as $row)错误@empty空@endforelse@pt:end|@pt:test_rows(items=[],empty="<空>")错误@pt:end',
            ['p' => '外层'],
        ));
        self::assertSame('允许', $this->renderTheme('@pt:test_perm(allowed=true)@if(true)允许@else错误@endif@else拒绝@pt:end'));
    }

    public function testNestedBusinessLoopsRestoreCurrentItemAndVariablesAfterBreak(): void
    {
        self::assertSame('内层外层外层已清理', $this->renderTheme(
            '@pt:test_rows(items=$items,as=row)@pt:test_rows(items=$row.children,as=row){{ $row["title"] }}@break@pt:end{{ $row["title"] }}@pt:test_label(value=$current_item.title)@pt:end{{ isset($row) || isset($current_item) ? "泄漏" : "已清理" }}',
            ['items' => [['title' => '外层', 'children' => [['title' => '内层'], ['title' => '跳过']]]]],
        ));
    }

    public function testLegacyFieldsAndConditionsReadCurrentBladeVariables(): void
    {
        self::assertSame('标题|$row.title', $this->renderTheme(
            '@foreach($items as $row)@if($row.visible && !$missing){$row.title(limit=4,pl="")}@endif@endforeach|@if(\'$row.title\' === \'$row.title\'){{ \'$row.title\' }}@else错误@endif',
            ['items' => [['title' => '标题内容', 'visible' => true]]],
        ));
    }

    public function testLiteralBlocksEscapesAndPhpStringsDoNotExecuteDirectives(): void
    {
        self::assertSame('@pt:test_label(value="x") {{ $missing }}|@pt:test_label(value="x")|{$missing}|{{ $missing }}|@pt:test_label(value="x")', $this->renderTheme(
            '@verbatim@pt:test_label(value="x") {{ $missing }}@endverbatim|@@pt:test_label(value="x")|@{$missing}|@{{ $missing }}|@php $literal = \'@pt:test_label(value="x")\'; @endphp{!! $literal !!}',
        ));
    }

    public function testNativeExpressionsSupportNestedCallsBracesAndQuotedParentheses(): void
    {
        self::assertSame('完成', $this->renderTheme('@if(strlen(trim(")")) === 1){{ (function () { return "完成"; })() }}@endif'));
        self::assertSame('完成', $this->renderTheme('@php($label = "value")@if("$label.title" === "value.title")完成@endif'));
    }

    public function testBusinessStateIsSharedWithIncludesButNotSubsequentRenders(): void
    {
        $registration = $this->registry->forNamespace('state');
        $registration->define('rows')->loop(static function (array $arguments, array $runtime): array {
            $runtime['context']['__ptadmin_render_state']['total'] = 2;
            return [['title' => '标题']];
        })->commit();
        $registration->define('total')->output(static fn (array $arguments, array $context): int => $context['__ptadmin_render_state']['total'] ?? 0)->commit();
        $registration->commit();
        $this->files->put($this->root.'/pager.blade.php', '@pt:state_total()');
        self::assertSame('标题2', $this->renderTheme('@pt:state_rows(as=row){{ $row["title"] }}@pt:end@include("pager")'));
        self::assertSame('0', $this->renderTheme('@pt:state_total()'));
    }

    public function testCachedThemeUsesCurrentDataWithoutExecutingBusinessDuringCompilation(): void
    {
        $this->themes->load(new ThemeDescriptor('test', '1.0.0', $this->root, []));
        $path = $this->root.'/cache.blade.php';
        $this->files->put($path, '@pt:test_rows(items=$items,as=row){{ $row["title"] }}@pt:end');
        $this->engine->getCompiler()->compile($path);
        self::assertSame('第一项', $this->viewFactory->make('cache', ['items' => [['title' => '第一项']]])->render());
        self::assertSame('第二项', $this->viewFactory->make('cache', ['items' => [['title' => '第二项']]])->render());
    }

    public function testBusinessContextPreservesItemMetadataAndHidesBladeServices(): void
    {
        $registration = $this->registry->forNamespace('context');
        $registration->define('kind')->output(static function (array $arguments, array $context): string {
            self::assertArrayNotHasKey('__env', $context);
            self::assertArrayNotHasKey('__ptadmin', $context);
            self::assertArrayNotHasKey('app', $context);
            return $context['current_item']['__kind'];
        })->commit();
        $registration->commit();
        self::assertSame('category', $this->renderTheme('@pt:test_rows(items=$items)@pt:context_kind()@pt:end', [
            'items' => [['__kind' => 'category']], 'app' => new \stdClass(),
        ]));
    }

    private function renderTheme(string $source, array $data = []): string
    {
        $this->themes->load(new ThemeDescriptor('test', '1.0.0', $this->root, []));
        $name = 'fixture'.++$this->fixtureSequence;
        $this->files->put($this->root.'/'.$name.'.blade.php', $source);
        $this->engine->forgetCompiledOrNotExpired();
        return $this->viewFactory->make($name, $data)->render();
    }
}
