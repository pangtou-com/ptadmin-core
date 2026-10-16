<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Template\Runtime;

use PHPUnit\Framework\TestCase;
use PTAdmin\Template\Directive\DirectiveRegistry;
use PTAdmin\Template\Runtime\TemplateRenderer;

final class BranchDirectiveTest extends TestCase
{
    public function testBranchScopesResultInBothArmsAndRestoresOuterVariables(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->forNamespace('test');
        $registration->define('perm')->branch(static fn (array $args) => ['allowed' => $args['allowed'], 'reason' => '<无权限>'])
            ->parameter('allowed', 'boolean', required: true)->commit();
        $registration->commit();
        $renderer = new TemplateRenderer($registry);
        self::assertSame('允许/外层', $renderer->render('@pt:test_perm(allowed=true,as=p)允许@else{$p.reason}@pt:end/{$p.reason}', ['p' => ['reason' => '外层']]));
        self::assertSame('&lt;无权限&gt;/外层', $renderer->render('@pt:test_perm(allowed=false,as=p)允许@else{$p.reason}@pt:end/{$p.reason}', ['p' => ['reason' => '外层']]));
        self::assertSame('内层拒绝', $renderer->render('@pt:test_perm(allowed=true)@pt:test_perm(allowed=false)内层允许@else内层拒绝@pt:end@else外层拒绝@pt:end'));
        self::assertSame('条件', $renderer->render('@pt:test_perm(allowed=true)@if(true)条件@else其他@endif@else拒绝@pt:end'));
    }

    public function testBranchRejectsTruthyNonBooleanPermission(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->forNamespace('test');
        $registration->define('perm')->branch(static fn () => ['allowed' => 'false'])->commit();
        $registration->commit();
        $this->expectException(\InvalidArgumentException::class);
        (new TemplateRenderer($registry))->render('@pt:test_perm()不应输出@pt:end');
    }
}
