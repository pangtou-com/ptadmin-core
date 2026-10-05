<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Template\Runtime;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PTAdmin\Template\Directive\DirectiveRegistry;
use PTAdmin\Template\Runtime\TemplateRenderer;

final class TemplateSequenceTest extends TestCase
{
    public function testListsShareStateInSourceOrderWithoutReinterpretingData(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->forNamespace('test');
        $registration->define('list')->loop(static function (array $arguments, array $runtime): array {
            $runtime['context']['__ptadmin_render_state']['total'] = $arguments['total'];
            return [['title' => '@pt:test_pager() {$other.title}']];
        })->parameter('total', 'integer')->commit();
        $registration->define('pager')->output(static fn (array $arguments, array $context): int => $context['__ptadmin_render_state']['total'] ?? 0)->commit();
        $registration->commit();
        $renderer = new TemplateRenderer($registry);
        self::assertSame('0@pt:test_pager() {$other.title}2@pt:test_pager() {$other.title}3', $renderer->render(
            '@pt:test_pager()@pt:test_list(total=2,id=article){$article.title}@pt:end@pt:test_pager()@pt:test_list(total=3,as=article){$article.title}@pt:end@pt:test_pager()',
        ));
        self::assertSame('0', $renderer->render('@pt:test_pager()'));
    }

    public function testEmptyBranchDependsOnCollectionAndAliasesCannotConflict(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->forNamespace('test');
        $registration->define('rows')->loop(static fn (): array => [['title' => '']])->commit();
        $registration->commit();
        $renderer = new TemplateRenderer($registry);
        self::assertSame('', $renderer->render('@pt:test_rows(as=row,id=row){$row.title}@pt:empty wrong@pt:end'));
        $this->expectException(InvalidArgumentException::class);
        $renderer->render('@pt:test_rows(as=row,id=other)@pt:end');
    }

    public function testRawAndEscapedValuesAreNotReinterpreted(): void
    {
        $renderer = new TemplateRenderer(new DirectiveRegistry());
        self::assertSame('<p>{$other.title}</p>@pt:unknown()', $renderer->render('{: $body}{$title}', ['body' => new \PTAdmin\Template\Runtime\SafeHtml('<p>{$other.title}</p>'), 'title' => '@pt:unknown()']));
    }
    public function testRestrictedBladeConditionsAndLiteralOutputs(): void
    {
        $renderer = new TemplateRenderer(new DirectiveRegistry());
        self::assertSame('yes &lt;safe&gt;', $renderer->render('@if ($count > 1 && !$hidden)yes {{ $title ?? "fallback" }}@elseif($count == 1)one@else empty@endif', ['count' => 2, 'hidden' => false, 'title' => '<safe>']));
        self::assertSame('one', $renderer->render('@if($count > 1)many@elseif($count == 1)one@else none@endif', ['count' => 1]));
        self::assertSame('@pt:unknown()', $renderer->render('@@pt:unknown()'));
        $this->expectException(InvalidArgumentException::class);
        $renderer->render('@if(system("id"))bad@endif');
    }

    public function testRawHtmlRequiresPluginDeclaration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TemplateRenderer(new DirectiveRegistry()))->render('{: $body}', ['body' => '<script>bad</script>']);
    }

    public function testInvalidNestedArgumentsAreRejectedEvenForEmptyOuterData(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->forNamespace('test');
        $registration->define('rows')->loop(static fn (): array => [])->commit();
        $registration->commit();
        $this->expectException(InvalidArgumentException::class);
        (new TemplateRenderer($registry))->render('@pt:test_rows()@pt:test_rows(unknown=1)@pt:end@pt:end');
    }
}
