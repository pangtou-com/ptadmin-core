<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Schema;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use PTAdmin\Schema\Runtime\RuntimeBindingExecutor;
use PTAdmin\Schema\Runtime\RuntimeDiagnostic;
use PTAdmin\Schema\Runtime\RuntimeHookContext;
use PTAdmin\Schema\Runtime\RuntimeRegistry;

final class RuntimeBindingExecutorTest extends TestCase
{
    public function testPrepareAndOutputUseAnOrderedArrayPipeline(): void
    {
        $container = new Container();
        $registry = new RuntimeRegistry();
        $resource = $registry->forOwner('core', 'ptadmin')->resource('cms.articles');
        $resource->hook('create.prepare', RuntimeBindingHandler::class, 'prepareFirst', 10);
        $resource->hook('create.prepare', RuntimeBindingHandler::class, 'prepareSecond', 0);
        $resource->hook('output.transform', RuntimeBindingHandler::class, 'output');
        $snapshot = $registry->build();
        $executor = new RuntimeBindingExecutor($container);

        self::assertSame(['first' => true, 'second' => true], $executor->prepare(
            $snapshot,
            new RuntimeHookContext('cms.articles', 'create.prepare'),
            [],
        ));
        self::assertSame(['output' => true], $executor->transform(
            $snapshot,
            new RuntimeHookContext('cms.articles', 'output.transform'),
            [],
        ));
    }

    public function testListPrepareUsesTheRuntimePipeline(): void
    {
        $container = new Container();
        $registry = new RuntimeRegistry();
        $registry->forOwner('core', 'ptadmin')->resource('cms.articles')
            ->hook('list.prepare', RuntimeBindingHandler::class, 'prepareFirst');

        self::assertSame(['first' => true], (new RuntimeBindingExecutor($container))->prepare(
            $registry->build(),
            new RuntimeHookContext('cms.articles', 'list.prepare'),
            [],
        ));
    }

    public function testActionRequiresAndReturnsAnArray(): void
    {
        $container = new Container();
        $registry = new RuntimeRegistry();
        $registry->forOwner('core', 'ptadmin')->resource('cms.articles')
            ->action('publish', RuntimeBindingHandler::class, 'action');
        $snapshot = $registry->build();
        $executor = new RuntimeBindingExecutor($container);

        self::assertSame(['published' => true], $executor->action(
            $snapshot,
            new RuntimeHookContext('cms.articles', 'action', actionKey: 'publish'),
            ['id' => 10],
        ));
        $this->expectException(RuntimeDiagnostic::class);
        $this->expectExceptionMessage('binding is unavailable');
        $executor->action($snapshot, new RuntimeHookContext('cms.articles', 'action', actionKey: 'missing'), []);
    }

    public function testListTransformReceivesTheCurrentPageAsOneBatch(): void
    {
        $registry = new RuntimeRegistry();
        $registry->forOwner('app', 'host')->resource('site.developers')
            ->hook('list.transform', RuntimeBindingHandler::class, 'summarize');
        $executor = new RuntimeBindingExecutor(new Container());
        $context = new RuntimeHookContext('site.developers', 'list.transform', input: ['page' => 2]);
        self::assertSame([['id' => 7, 'page_size' => 2], ['id' => 9, 'page_size' => 2]], $executor->transform(
            $registry->build(), $context, [['id' => 7], ['id' => 9]],
        ));
        self::assertSame([], $executor->transform($registry->build(), $context, []));
    }
}

final class RuntimeBindingHandler
{
    public function summarize(RuntimeHookContext $context, array $rows): array
    {
        return array_map(static fn (array $row): array => $row + ['page_size' => count($rows)], $rows);
    }

    public function prepareFirst(RuntimeHookContext $context, array $data): array
    {
        return $data + ['first' => true];
    }

    public function prepareSecond(RuntimeHookContext $context, array $data): array
    {
        return $data + ['second' => true];
    }

    public function output(RuntimeHookContext $context, array $data): array
    {
        return ['output' => true];
    }

    public function action(RuntimeHookContext $context, array $input): array
    {
        return ['published' => true];
    }
}
