<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon;

use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\AddonBuiltinTemplateDirectives;
use PTAdmin\Addon\AddonTemplateDirectiveRegistry;
use PTAdmin\Addon\AddonTemplateRuntime;
use PTAdmin\Addon\TemplateDirectiveArgumentParser;

final class AddonTemplateRuntimeTest extends TestCase
{
    public function testArgumentParserSupportsArraysAndVariables(): void
    {
        $parser = new TemplateDirectiveArgumentParser();
        self::assertSame(['limit' => 2, 'filters' => ['a', ['b' => true]], 'value' => 'Title'], $parser->evaluate('limit=2,filters=["a",["b"=true]],value=$item.title', ['item' => ['title' => 'Title']]));
    }

    public function testLoopAndFieldOutputRestoreContextAndEscape(): void
    {
        $registry = new AddonTemplateDirectiveRegistry();
        $registry->define('articles')->loop(static function (array $arguments, array $invokeContext): array {
            return $invokeContext['context']['items'] ?? [['title' => '<A>'], ['title' => 'B']];
        })->commit('demo');
        $runtime = new AddonTemplateRuntime($registry);
        self::assertSame('&lt;A&gt;B', $runtime->render('@pt:demo_articles(as=item){$item.title}@pt:end'));
        self::assertSame(' empty', $runtime->render('@pt:demo_articles(as=item)X@pt:empty empty@pt:end', ['items' => []]));
    }

    public function testNestedLoopsRestoreOuterVariableContext(): void
    {
        $registry = new AddonTemplateDirectiveRegistry();
        $registry->define('outer')->loop(static fn (): array => [['title' => 'A']])->commit('demo');
        $registry->define('inner')->loop(static fn (): array => [['name' => 'I']])->commit('demo');
        $runtime = new AddonTemplateRuntime($registry);

        self::assertSame('A:I:A', $runtime->render('@pt:demo_outer(as=outer){$outer.title}:@pt:demo_inner(as=inner){$inner.name}:{$outer.title}@pt:end@pt:end'));
    }

    public function testBuiltinMediaDirectivesShareParametersAndEscapeAttributes(): void
    {
        $registry = new AddonTemplateDirectiveRegistry();
        (new AddonBuiltinTemplateDirectives())->register($registry);
        $runtime = new AddonTemplateRuntime($registry);
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
    }

    public function testMediaUsesOnlyMarkedSchemaContextAndReportsAmbiguity(): void
    {
        $registry = new AddonTemplateDirectiveRegistry();
        (new AddonBuiltinTemplateDirectives())->register($registry);
        $runtime = new AddonTemplateRuntime($registry);
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
        $registry = new AddonTemplateDirectiveRegistry();
        (new AddonBuiltinTemplateDirectives())->register($registry);
        $runtime = new AddonTemplateRuntime($registry);

        self::assertSame('<title>Demo &amp; title</title><meta name="description" content="Description">', $runtime->render('@pt:seo(only=["title","description"])', ['seo' => ['title' => 'Demo & title', 'description' => 'Description']]));
        self::assertSame('', $runtime->render('@pt:dump(context=["secret"="value"])', ['environment' => 'production']));
        self::assertStringContainsString('value', $runtime->render('@pt:dump(context=["secret"="value"])', ['environment' => 'local']));
        self::assertSame("<pre><strong>Context</strong>\nArray\n(\n    [secret] =&gt; value\n)\n</pre>", $runtime->render('@pt:dump(context=["secret"="value"],label="Context",once=true)@pt:dump(context=["secret"="value"],label="Context",once=true)', ['environment' => 'local']));

        $registry->define('unsafe')->output(static fn (): string => '<b>unsafe</b>')->commit('demo');
        $registry->define('safe')->output(static fn (): string => '<b>safe</b>')->safeHtml()->commit('demo');
        self::assertSame('&lt;b&gt;unsafe&lt;/b&gt;', $runtime->render('@pt:demo_unsafe()'));
        self::assertSame('<b>safe</b>', $runtime->render('@pt:demo_safe()'));

        $metadata = $registry->metadata();
        self::assertSame('output', $metadata['core_img']['type']);
        self::assertSame('img', $metadata['core_img']['alias']);
        self::assertSame('integer', $metadata['core_images']['parameters']['limit']['type']);
    }
}
