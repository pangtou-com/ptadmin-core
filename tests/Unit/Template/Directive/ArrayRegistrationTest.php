<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Template\Directive;

use Illuminate\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PTAdmin\Template\Directive\DirectiveRegistry;
use PTAdmin\Template\Runtime\TemplateRenderer;
use RuntimeException;

final class ArrayRegistrationTest extends TestCase
{
    public function testBothEntrypointsExportIdenticalMetadataAndRenderAllDirectiveTypes(): void
    {
        $declarations = [
            'text' => [
                'type' => 'output', 'alias' => 'headline', 'description' => '输出标题。',
                'handler' => static fn (array $args) => $args['title'],
                'parameters' => ['title' => ['type' => 'string', 'required' => true, 'description' => '标题文字。', 'example' => '<标题>']],
                'context' => ['page.title' => '页面标题。'], 'cache' => ['enabled' => false], 'safe_html' => false,
                'returns' => ['description' => '已转义文字。'], 'examples' => ['@pt:demo_text(title="标题")'],
            ],
            'items' => [
                'type' => 'loop', 'description' => '读取当前页面的条目。',
                'handler' => static fn (array $args, array $runtime) => array_slice($runtime['context']['items'], 0, $args['limit']),
                'parameters' => [
                    'limit' => ['type' => 'integer', 'default' => 2, 'min' => 1, 'max' => 3, 'description' => '条目数量。'],
                    'order' => ['type' => 'string', 'enum' => ['oldest', 'newest'], 'description' => '由业务上下文决定默认排序。'],
                    'ids' => ['type' => 'array', 'item_type' => 'integer', 'deprecated' => true],
                    'optional' => ['type' => 'string', 'default' => null],
                ],
                'returns' => ['description' => '逐条绑定的展示对象。', 'fields' => ['title' => ['type' => 'string', 'description' => '条目标题。']]],
                'examples' => ['@pt:demo_items(as=item){$item.title}@pt:end'],
            ],
            'permission' => [
                'type' => 'branch', 'handler' => static fn (array $args) => ['allowed' => $args['allowed'], 'reason' => '拒绝'],
                'parameters' => ['allowed' => ['type' => 'boolean', 'default' => false]],
                'returns' => ['fields' => ['allowed' => ['type' => 'boolean', 'description' => '是否允许。']]],
                'examples' => ['@pt:demo_permission()允许@else拒绝@pt:end'],
            ],
        ];
        $arrayRegistry = new DirectiveRegistry();
        $arrays = $arrayRegistry->begin('demo');
        $arrays->registerMany($declarations);
        self::assertSame([], $arrayRegistry->all(), '声明应留在当前事务中，不能提前注册。');
        $arrays->commit();

        $chainRegistry = new DirectiveRegistry();
        $chain = $chainRegistry->begin('demo');
        foreach ($declarations as $name => $declaration) {
            $builder = $chain->define($name);
            $builder->{$declaration['type']}($declaration['handler']);
            $builder->description($declaration['description'] ?? '')->context($declaration['context'] ?? [])
                ->cache($declaration['cache'] ?? [])->safeHtml($declaration['safe_html'] ?? false)
                ->returns($declaration['returns'])->examples($declaration['examples']);
            if (isset($declaration['alias'])) {
                $builder->alias($declaration['alias']);
            }
            foreach ($declaration['parameters'] as $parameter => $specification) {
                if (isset($specification['item_type'])) {
                    $specification['itemType'] = $specification['item_type'];
                    unset($specification['item_type']);
                }
                $builder->parameter($parameter, ...$specification);
            }
            $builder->commit();
        }
        $chain->commit();
        self::assertSame($chainRegistry->metadata(), $arrayRegistry->metadata());
        self::assertFalse($arrayRegistry->metadata()['demo_items']['parameters']['order']['has_default']);
        self::assertTrue($arrayRegistry->metadata()['demo_items']['parameters']['optional']['has_default']);
        $encoded = json_encode($arrayRegistry->metadata(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('handler', $encoded);
        $template = '@pt:headline(title="<标题>")|@pt:demo_items(as=i){$i.title}@pt:end|'
            .'@pt:demo_permission(allowed=true)允许@else错误@pt:end|@pt:demo_permission()错误@else拒绝@pt:end';
        foreach ([$chainRegistry, $arrayRegistry] as $registry) {
            self::assertSame('&lt;标题&gt;|AB|允许|拒绝', (new TemplateRenderer($registry))->render($template, ['items' => [['title' => 'A'], ['title' => 'B'], ['title' => 'C']]]));
            foreach (['items(limit=0)', 'items(limit=4)', 'items(order=other)', 'items(ids=["x"])', 'text()', 'items(unknown=1)'] as $invocation) {
                try {
                    (new TemplateRenderer($registry))->render('@pt:demo_'.$invocation.(str_starts_with($invocation, 'items') ? '@pt:end' : ''));
                    self::fail('两种入口都应拒绝非法参数：'.$invocation);
                } catch (InvalidArgumentException $error) {
                    self::assertStringContainsString('demo_', $error->getMessage());
                }
            }
        }
    }

    public function testBothEntrypointsResolveHandlerDependenciesAtInvocationTime(): void
    {
        $previous = Container::getInstance();
        $container = new Container();
        Container::setInstance($container);
        $resolved = 0;
        $container->bind(RegistrationTestHandler::class, static function () use (&$resolved): RegistrationTestHandler {
            $resolved++;
            return new RegistrationTestHandler('容器注入：');
        });
        try {
            $registry = new DirectiveRegistry();
            $registration = $registry->begin('demo');
            $registration->registerMany(['array_text' => ['handler' => [RegistrationTestHandler::class, 'text'], 'parameters' => ['text' => ['type' => 'string']]]]);
            $registration->define('chain_text')->output([RegistrationTestHandler::class, 'text'])->parameter('text')->commit();
            $registration->commit();
            self::assertSame(0, $resolved);
            self::assertSame('容器注入：A|容器注入：B', (new TemplateRenderer($registry))->render('@pt:demo_array_text(text="A")|@pt:demo_chain_text(text="B")'));
            self::assertSame(2, $resolved);
        } finally {
            Container::setInstance($previous);
        }
    }

    public function testInvalidArrayBatchDoesNotLeavePartiallyRegisteredDefinitions(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->begin('demo');
        $registration->define('existing')->output(static fn () => '保留')->commit();
        try {
            $registration->registerMany([
                'valid' => ['handler' => static fn () => '不应残留'],
                'invalid' => ['handler' => static fn () => '', 'parameters' => ['limit' => ['typo' => 1]]],
            ]);
            self::fail('应拒绝未知参数声明字段。');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('demo_invalid', $error->getMessage());
        }
        $registration->commit();
        self::assertSame(['demo_existing'], array_keys($registry->all()));
    }

    public function testMixedEntrypointsRejectConflictsWithoutPublishingAnyDefinition(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->begin('demo');
        $registration->define('same')->output(static fn () => '链式')->commit();
        $registration->registerMany(['same' => ['handler' => static fn () => '数组']]);
        try {
            $registration->commit();
            self::fail('同名指令必须拒绝整批提交。');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('demo_same', $error->getMessage());
        }
        self::assertSame([], $registry->all());
    }

    #[DataProvider('invalidDeclarations')]
    public function testArrayDeclarationRejectsInvalidDefinitions(array $declaration): void
    {
        $registration = (new DirectiveRegistry())->begin('demo');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('demo_invalid');
        $registration->registerMany(['invalid' => $declaration]);
    }

    public static function invalidDeclarations(): iterable
    {
        $handler = static fn () => [];
        yield '缺少处理器' => [[]];
        yield '非法处理器' => [['handler' => ['MissingClass', 'method']]];
        yield '未知顶层字段' => [['handler' => $handler, 'typo' => true]];
        yield '非法指令类型' => [['handler' => $handler, 'type' => 'invalid']];
        yield '参数不是映射' => [['handler' => $handler, 'parameters' => 'invalid']];
        yield '参数不是声明' => [['handler' => $handler, 'parameters' => ['id' => 'integer']]];
        yield '参数类型错误' => [['handler' => $handler, 'parameters' => ['id' => ['type' => 'invalid']]]];
        yield '参数属性类型错误' => [['handler' => $handler, 'parameters' => ['id' => ['required' => 'yes']]]];
        yield '保留绑定参数' => [['handler' => $handler, 'type' => 'loop', 'parameters' => ['as' => []]]];
        yield '示例不是列表' => [['handler' => $handler, 'examples' => ['label' => '源码']]];
        yield '示例不是字符串' => [['handler' => $handler, 'examples' => [42]]];
    }

    public function testArrayRegistrationCannotBeReusedAfterCommit(): void
    {
        $registration = (new DirectiveRegistry())->begin('demo');
        $registration->commit();
        $this->expectException(RuntimeException::class);
        $registration->registerMany([]);
    }
}

/** 测试处理器需要构造参数，确认执行阶段确实使用当前容器。 */
final class RegistrationTestHandler
{
    public function __construct(private readonly string $prefix)
    {
    }

    public function text(array $arguments): string
    {
        return $this->prefix.$arguments['text'];
    }
}
