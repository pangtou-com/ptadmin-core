<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Template\Runtime;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PTAdmin\Template\Builtin\BuiltinDirectiveProvider;
use PTAdmin\Template\Directive\DirectiveRegistry;
use PTAdmin\Template\Navigation\Pagination;
use PTAdmin\Template\Runtime\TemplateRenderer;

final class PageNavigationTest extends TestCase
{
    private function registry(): DirectiveRegistry
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->forNamespace('core');
        (new BuiltinDirectiveProvider())->register($registration);
        $registration->commit();
        return $registry;
    }

    public function testBreadcrumbsWorkWithoutAnyPluginAndEscapeTheirOutput(): void
    {
        $renderer = new TemplateRenderer($this->registry());
        $context = ['breadcrumbs' => [['title' => '<目录>', 'url' => '/docs?a=1&b=2'], ['title' => '详情', 'url' => '/detail']]];
        self::assertSame('首页|<目录>|详情|', html_entity_decode($renderer->render('@pt:crumb_data(as=c){$c.title}|@pt:end', $context)));
        $html = $renderer->render('@pt:crumb(separator="<",home="<首页>")', $context);
        self::assertStringContainsString('&lt;首页&gt;', $html);
        self::assertStringContainsString('href="/docs?a=1&amp;b=2"', $html);
        self::assertStringContainsString('<span aria-current="page">详情</span>', $html);
        self::assertStringNotContainsString('<目录>', $html);
        self::assertSame('首页', $renderer->render('@pt:crumb_data(current=false,as=c){$c.title}@pt:end', $context));
        self::assertSame('目录', $renderer->render('@pt:core_crumb_data(items=[[title="目录",url="/"]],as=c){$c.title}@pt:end'));
        self::assertSame('空', $renderer->render('@pt:crumb_data(items=[],empty="空")@pt:end'));
        $unsafe = $renderer->render('@pt:crumb(items=$items)', ['items' => [
            ['title' => '危险地址', 'url' => 'javascript:alert(1)', 'is_current' => false],
            ['title' => '当前页', 'url' => '/current'],
        ]]);
        self::assertStringContainsString('<span>危险地址</span>', $unsafe);
        self::assertStringNotContainsString('javascript:', $unsafe);
    }

    public function testPaginationAcceptsExplicitDataAndPreservesQueryParameters(): void
    {
        $renderer = new TemplateRenderer($this->registry());
        $context = ['pagination' => ['total' => 25, 'per_page' => 10, 'current_page' => 2],
            'current_path' => '/products', 'query' => ['keyword' => 'a&b', 'pages' => ['other' => 4]]];
        $template = '@pt:pager_data(as=p){$p.total}/{$p.last_page}/{$p.previous_url}/{$p.next_url}@pt:end';
        self::assertSame('25/3//products?keyword=a%26b&pages%5Bother%5D=4&page=1//products?keyword=a%26b&pages%5Bother%5D=4&page=3', html_entity_decode($renderer->render($template, $context)));
        self::assertStringContainsString('aria-current="page">2', $renderer->render('@pt:core_pager(layout="compact")', $context));
        self::assertSame('2', $renderer->render('@pt:pager_data(data=[total=6,per_page=3,current_page=1],as=p){$p.last_page}@pt:end'));
        self::assertSame('1', $renderer->render('@pt:pager_data(total=0,as=p){$p.last_page}@pt:end'));
        self::assertSame('', $renderer->render('@pt:pager(total=0)'));
        self::assertStringContainsString('共 0 条', $renderer->render('@pt:pager(total=0,show_single=true)'));
        self::assertSame('10/0', $renderer->render('@pt:pager_data(total=0,page=10,as=p){$p.current_page}/{$p.total}@pt:end'));
    }

    public function testListProducersSharePaginationWithoutLeakingAcrossRenders(): void
    {
        $registry = $this->registry();
        $registration = $registry->forNamespace('shop');
        $pagination = new Pagination();
        $registration->define('products')->loop(static function (array $arguments, array $runtime) use ($pagination): array {
            $pagination->remember(['total' => 25, 'per_page' => 10, 'current_page' => 2, 'last_page' => 3], $runtime['context'], 'products',
                static fn (int $page): string => '/products/page-'.$page);
            $pagination->remember(['total' => 8, 'per_page' => 2, 'current_page' => 1], $runtime['context'], 'other');
            return [['title' => '商品']];
        })->commit();
        $registration->commit();
        $renderer = new TemplateRenderer($registry);
        self::assertSame('商品|/products/page-3|4|5', $renderer->render(
            '@pt:shop_products(as=p){$p.title}@pt:end|@pt:pager_data(page_id=products,as=p){$p.next_url}@pt:end|'
            .'@pt:pager_data(as=p){$p.last_page}@pt:end|@pt:pager_data(pager=products,per_page=5,as=p){$p.last_page}@pt:end'));
        self::assertSame('/?pages%5Bother%5D=2', html_entity_decode($renderer->render('@pt:shop_products()@pt:end@pt:pager_data(as=p){$p.next_url}@pt:end')));
        $this->expectException(InvalidArgumentException::class);
        $renderer->render('@pt:pager()');
    }

    public function testInvalidInputsAndCmsSelectorsAreRejectedByCore(): void
    {
        $renderer = new TemplateRenderer($this->registry());
        foreach (['@pt:crumb(cid=1)', '@pt:crumb(items=[1])', '@pt:pager(total=-1)', '@pt:pager(total=1,per_page=0)',
            '@pt:pager(total=1,window=21)', '@pt:pager_data(data=[total="1"])@pt:end',
            '@pt:pager(total=30,layout="unknown")', '@pt:pager(total=1,page_id=a,pager=b)'] as $template) {
            try {
                $renderer->render($template);
                self::fail('必须拒绝无效参数：'.$template);
            } catch (InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        $registry = $this->registry();
        self::assertSame('core', $registry->get('crumb')->namespace());
        self::assertSame('core', $registry->get('pager')->namespace());
        $registration = $registry->forNamespace('shop');
        $registration->define('pager')->alias('pager')->output(static fn (): string => '')->commit();
        $this->expectException(\RuntimeException::class);
        $registration->commit();
    }
}
