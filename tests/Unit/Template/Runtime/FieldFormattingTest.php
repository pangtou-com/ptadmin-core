<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Template\Runtime;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PTAdmin\Template\Directive\DirectiveRegistry;
use PTAdmin\Template\Runtime\SafeHtml;
use PTAdmin\Template\Runtime\TemplateRenderer;

final class FieldFormattingTest extends TestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Shanghai');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        date_default_timezone_set($this->timezone);
    }

    public function testDefaultsPreserveZeroAndFalseAndSupportOldAndCurrentSyntax(): void
    {
        $renderer = new TemplateRenderer(new DirectiveRegistry());
        self::assertSame('默认|默认|默认|后备|0|0||', $renderer->render(
            '{$missing|默认}|{$blank|default="默认"}|{$blank(default="默认")}|{$missing(default=$fallback)}|{$zero(default="错误")}|{$stringZero(default="错误")}|{$false(default="错误")}|{{ $blank ?? "错误" }}',
            ['blank' => '', 'fallback' => '后备', 'zero' => 0, 'stringZero' => '0', 'false' => false],
        ));
    }

    public function testTextUsesLegacyDisplayWidthAndEscapesOnlyAfterFormatting(): void
    {
        $renderer = new TemplateRenderer(new DirectiveRegistry());
        self::assertSame('中文...|中文标题|&lt;&amp;|标题|标题', $renderer->render(
            '{$title(limit=$width)}|{$title(limit=8)}|{$html(limit=2,pl="")}|{$padded(func=trim)}|{$rich(func=strip_tags)}',
            ['title' => '中文标题', 'width' => 4, 'html' => '<&bad>', 'padded' => ' 标题 ', 'rich' => new SafeHtml('<p>标题</p>')],
        ));
    }

    public function testQuotedPunctuationAndDirectiveTextAreOnlyOutputOnce(): void
    {
        $renderer = new TemplateRenderer(new DirectiveRegistry());
        self::assertSame('a),} @pt:unknown() {$secret}|{$title(limit=2)}|@pt:unknown()', $renderer->render(
            '{$missing(default="a),} @pt:unknown() {$secret}")}|@{$title(limit=2)}|{$value(func=trim)}',
            ['secret' => '不能泄漏', 'value' => ' @pt:unknown() '],
        ));
        self::assertSame('path\\', $renderer->render('{$missing(default="path\\\\",limit=100)}'));
    }

    public function testDatesRespectTimezoneEpochAndImmutableDateObjects(): void
    {
        $renderer = new TemplateRenderer(new DirectiveRegistry());
        $date = new \DateTimeImmutable('2026-10-05T02:30:00+00:00');
        self::assertSame('2026-10-05 10:30|1970-01-01 08:00|', $renderer->render(
            '{$date(format_date="Y-m-d H:i")}|{$epoch(format_date="Y-m-d H:i")}|{$missing(format_date="Y")}', ['date' => $date, 'epoch' => 0],
        ));
        self::assertSame('+00:00', $date->format('P'));
    }

    public function testRelativeTimeHasPastFutureAndZeroBoundaries(): void
    {
        $renderer = new TemplateRenderer(new DirectiveRegistry());
        foreach ([0 => '刚刚', 1 => '1秒前', 59 => '59秒前', 60 => '1分钟前', 3600 => '1小时前', 86400 => '1天前', 604800 => '1周前', 2592000 => '1个月前', 31536000 => '1年前', -60 => '1分钟后'] as $seconds => $expected) {
            self::assertSame($expected, $renderer->render('{$date(format_before)}', ['date' => CarbonImmutable::now()->subSeconds($seconds)]));
        }
    }

    public function testMoneyRoundingZeroSectionsAndLargeValuesDoNotLosePrecision(): void
    {
        $renderer = new TemplateRenderer(new DirectiveRegistry());
        foreach (['0' => '零元整', '0.05' => '零元伍分', '-0.004' => '零元整', '9.995' => '壹拾元整', '-1,234.56' => '负壹仟贰佰叁拾肆元伍角陆分', '100010001.01' => '壹亿零壹万零壹元零壹分', '9999999999999999.99' => '玖仟玖佰玖拾玖兆玖仟玖佰玖拾玖亿玖仟玖佰玖拾玖万玖仟玖佰玖拾玖元玖角玖分'] as $amount => $expected) {
            self::assertSame($expected, $renderer->render('{$amount(format_money)}', ['amount' => (string) $amount]));
        }
    }

    public function testBadParametersAndValuesFailWithFieldContext(): void
    {
        $renderer = new TemplateRenderer(new DirectiveRegistry());
        foreach (['func=system', 'func="phpinfo()"', 'limit=0', 'limit=10001', 'limit="5"', 'pl="..."', 'limit=1,limit=2', 'unknown=true', 'format_before="true"', 'format_date="Y",format_money', 'format_date=""'] as $parameters) {
            try {
                $renderer->render('{$title('.$parameters.')}', ['title' => '2026-10-05']);
                self::fail('格式化未拒绝非法参数：'.$parameters);
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        foreach ([['date', 'format_date="Y-m-d"', '2026-02-30'], ['date', 'format_before', 'not-a-date'], ['amount', 'format_money', '1,23'], ['amount', 'format_money', '9999999999999999.995']] as [$field, $parameters, $value]) {
            try {
                $renderer->render('{$'.$field.'('.$parameters.')}', [$field => $value]);
                self::fail('无效数据未被拒绝');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($field, $exception->getMessage());
            }
        }
    }

    public function testFormattingCannotTurnTextIntoTrustedHtml(): void
    {
        $renderer = new TemplateRenderer(new DirectiveRegistry());
        self::assertSame('<p>正文</p>', $renderer->render('{: $body(default="")}', ['body' => new SafeHtml('<p>正文</p>')]));
        $this->expectException(InvalidArgumentException::class);
        $renderer->render('{: $body(limit=100)}', ['body' => new SafeHtml('<p>正文</p>')]);
    }

    public function testUnknownFormatInsideEmptyLoopIsStillRejected(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->forNamespace('test');
        $registration->define('rows')->loop(static fn (): array => [])->commit();
        $registration->commit();
        $this->expectException(InvalidArgumentException::class);
        (new TemplateRenderer($registry))->render('@pt:test_rows(as=row){$row.title(unknown=1)}@pt:end');
    }
}
