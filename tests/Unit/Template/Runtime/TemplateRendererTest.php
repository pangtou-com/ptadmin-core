<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Template\Runtime;

use PHPUnit\Framework\TestCase;
use PTAdmin\Template\Builtin\BuiltinDirectiveProvider;
use PTAdmin\Template\Directive\DirectiveRegistry;
use PTAdmin\Template\Runtime\TemplateRenderer;
use PTAdmin\Template\Parsing\DirectiveArgumentParser;

final class TemplateRendererTest extends TestCase
{
    public function testArgumentParserSupportsArraysAndVariables(): void
    {
        $parser = new DirectiveArgumentParser();
        self::assertSame(['limit' => 2, 'filters' => ['a', ['b' => true]], 'value' => 'Title'], $parser->evaluate('limit=2,filters=["a",["b"=true]],value=$item.title', ['item' => ['title' => 'Title']]));
        self::assertSame(['items' => []], $parser->evaluate('items=[]'));
        self::assertSame(['items' => ['a' => 'b']], $parser->evaluate('items=[a=>"b"]'));
        $this->expectException(\InvalidArgumentException::class);
        $parser->parse('value="closed"tail');
    }

    public function testLoopAndFieldOutputRestoreContextAndEscape(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->forNamespace('demo');
        $registration->define('articles')->loop(static function (array $arguments, array $invokeContext): array {
            return $invokeContext['context']['items'] ?? [['title' => '<A>'], ['title' => 'B']];
        })->commit();
        $registration->commit();
        $runtime = new TemplateRenderer($registry);
        self::assertSame('&lt;A&gt;B', $runtime->render('@pt:demo_articles(as=item){$item.title}@pt:end'));
        self::assertSame(' empty', $runtime->render('@pt:demo_articles(as=item)X@pt:empty empty@pt:end', ['items' => []]));
    }

    public function testNestedLoopsRestoreOuterVariableContext(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->forNamespace('demo');
        $registration->define('outer')->loop(static fn (): array => [['title' => 'A']])->commit();
        $registration->define('inner')->loop(static fn (): array => [['name' => 'I']])->commit();
        $registration->commit();
        $runtime = new TemplateRenderer($registry);

        self::assertSame('A:I:A', $runtime->render('@pt:demo_outer(as=outer){$outer.title}:@pt:demo_inner(as=inner){$inner.name}:{$outer.title}@pt:end@pt:end'));
    }

    public function testBuiltinMediaDirectivesShareParametersAndEscapeAttributes(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->forNamespace('core');
        (new BuiltinDirectiveProvider())->register($registration);
        $registration->commit();
        $runtime = new TemplateRenderer($registry);
        $context = [
            'media' => ['key' => '/uploads/cover.jpg', 'label' => 'Cover & title'],
            'gallery' => [
                ['key' => '/uploads/a.jpg', 'label' => 'A'],
                ['key' => '/uploads/b.jpg', 'label' => 'B'],
            ],
        ];

        self::assertSame('<img src="/uploads/cover.jpg" alt="Cover &amp; title">', $runtime->render('@pt:img()', $context));
        self::assertSame('<img src="/uploads/a.jpg" alt="A" loading="lazy">', $runtime->render('@pt:images(value=$gallery,limit=1,attrs=["loading"="lazy","onerror"="bad"])', $context));
        self::assertSame('<a href="/uploads/cover.jpg">Cover &amp; title</a>', $runtime->render('@pt:file()', $context));
        self::assertSame('<a href="/uploads/a.jpg">A</a>|<a href="/uploads/b.jpg">B</a>', $runtime->render('@pt:files(value=$gallery,separator="|")', $context));
        self::assertSame('none', $runtime->render('@pt:img(value=null,empty="none")', $context));
        self::assertSame('&lt;none&gt;', $runtime->render('@pt:img(value=null,empty="<none>")', $context));
        self::assertSame('<a href="/uploads/a.jpg">A</a>&lt;|&gt;<a href="/uploads/b.jpg">B</a>', $runtime->render('@pt:files(value=$gallery,separator="<|>")', $context));
    }

    public function testMediaUsesOnlyMarkedSchemaContextAndReportsAmbiguity(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->forNamespace('core');
        (new BuiltinDirectiveProvider())->register($registration);
        $registration->commit();
        $runtime = new TemplateRenderer($registry);
        $diagnostics = [];
        $context = [
            'schema_media' => ['unique' => ['key' => '/uploads/unique.jpg', 'label' => 'Unique']],
            'diagnostic' => static function (string $code, array $details) use (&$diagnostics): void {
                $diagnostics[] = [$code, $details];
            },
        ];

        self::assertSame('<img src="/uploads/unique.jpg" alt="Unique">', $runtime->render('@pt:img()', $context));
        self::assertSame('', $runtime->render('@pt:img()', [
            'schema_media' => ['candidates' => [
                ['key' => '/uploads/a.jpg'],
                ['key' => '/uploads/b.jpg'],
            ]],
            'diagnostic' => $context['diagnostic'],
        ]));
        self::assertSame([['media_ambiguous', ['count' => 2]]], $diagnostics);
    }

    public function testBuiltinSeoDumpAndMetadataContracts(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->forNamespace('core');
        (new BuiltinDirectiveProvider())->register($registration);
        $registration->commit();
        $runtime = new TemplateRenderer($registry);

        self::assertSame('<title>Demo &amp; title</title><meta name="description" content="Description">', $runtime->render('@pt:seo(only=["title","description"])', ['seo' => ['title' => 'Demo & title', 'description' => 'Description']]));
        self::assertSame('', $runtime->render('@pt:dump(context=["secret"="value"])', ['environment' => 'production']));
        self::assertStringContainsString('[secret] =&gt; [REDACTED]', $runtime->render('@pt:dump(context=["secret"="value","access_token"="another-value"])', ['environment' => 'local']));
        self::assertStringNotContainsString('value', $runtime->render('@pt:dump(context=["secret"="value"])', ['environment' => 'local']));
        self::assertSame("<pre><strong>Context</strong>\nArray\n(\n    [secret] =&gt; [REDACTED]\n)\n</pre>", $runtime->render('@pt:dump(context=["secret"="value"],label="Context",once=true)@pt:dump(context=["secret"="value"],label="Context",once=true)', ['environment' => 'local']));

        $demo = $registry->forNamespace('demo');
        $demo->define('unsafe')->output(static fn (): string => '<b>unsafe</b>')->commit();
        $demo->define('safe')->output(static fn (): string => '<b>safe</b>')->safeHtml()->commit();
        $demo->commit();
        self::assertSame('&lt;b&gt;unsafe&lt;/b&gt;', $runtime->render('@pt:demo_unsafe()'));
        self::assertSame('<b>safe</b>', $runtime->render('@pt:demo_safe()'));

        $metadata = $registry->metadata();
        self::assertSame('output', $metadata['core_img']['type']);
        self::assertSame('img', $metadata['core_img']['alias']);
        self::assertSame('integer', $metadata['core_images']['parameters']['limit']['type']);
    }

    public function testScannerSupportsParenthesesAndHyphenatedAddonNames(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->forNamespace('foo-bar');
        $registration->define('banner')->output(static fn (array $arguments): string => $arguments['text'])->parameter('text', type: 'string')->commit();
        $registration->commit();
        $other = $registry->forNamespace('foo_bar');
        $other->define('banner')->output(static fn (): string => 'underscore')->commit();
        $other->commit();
        $runtime = new TemplateRenderer($registry);

        self::assertSame('a)b', $runtime->render('@pt:foo-bar_banner(text="a)b")'));
        self::assertSame('underscore', $runtime->render('@pt:foo_bar_banner()'));
        self::assertSame('fallback', $runtime->render('{$item.title|default=$page.title}', ['item' => ['title' => ''], 'page' => ['title' => 'fallback']]));
    }

    public function testDirectiveParameterAndStructuralAliasValidation(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->forNamespace('demo');
        $this->expectException(\InvalidArgumentException::class);
        $registration->define('bad')->output(static fn (): string => '')->parameter('value', type: 'invalid');
    }

    public function testDirectiveParameterItemTypeAndErrorNamesAreValidated(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->forNamespace('demo');
        $registration->define('tags')
            ->output(static fn (): string => 'ok')
            ->parameter('values', type: 'array', itemType: 'string')
            ->commit();
        $registration->commit();
        $runtime = new TemplateRenderer($registry);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('demo_tags');
        $runtime->render('@pt:demo_tags(values=[1])');
    }

    public function testStructuralAliasesAreRejected(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->forNamespace('demo');
        $registration->define('loop')->loop(static fn (): array => [])->alias('end')->commit();
        $this->expectException(\RuntimeException::class);
        $registration->commit();
    }

    public function testStandaloneStructureKeywordsAreRejected(): void
    {
        $registry = new DirectiveRegistry();
        $runtime = new TemplateRenderer($registry);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unexpected template structure directive: end');
        $runtime->render('@pt:end');
    }
}
