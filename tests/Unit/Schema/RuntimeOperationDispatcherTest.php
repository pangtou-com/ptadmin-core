<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use PTAdmin\Schema\Contract\SchemaError;
use PTAdmin\Schema\Contracts\RuntimeEngineInterface;
use PTAdmin\Schema\Contracts\SchemaAuthorizationInterface;
use PTAdmin\Schema\Contracts\SchemaSubjectContextInterface;
use PTAdmin\Schema\Contracts\TreeServiceInterface;
use PTAdmin\Schema\Http\RuntimeOperationDispatcher;

final class RuntimeOperationDispatcherTest extends TestCase
{
    public function testActionSelectionAcceptsRecordsAndScalarIds(): void
    {
        $runtime = $this->createMock(RuntimeEngineInterface::class);
        $runtime->expects(self::once())
            ->method('executeAction')
            ->with([
                'resource' => 'demo.notes',
                'action' => 'archive',
                'id' => 0,
                'ids' => [12, 13, 14],
                'data' => ['reason' => 'expired'],
            ])
            ->willReturn(['success' => true]);

        $dispatcher = $this->dispatcher($runtime);
        self::assertSame(['success' => true], $dispatcher->dispatch('executeAction', [
            'resource' => 'demo.notes',
            'action' => 'archive',
            'selection' => [['id' => 12], (object) ['id' => '13'], 14, ['id' => 12]],
            'formValues' => ['reason' => 'expired'],
        ]));
    }

    public function testActionSelectionRejectsRecordsWithoutPrimaryKey(): void
    {
        $this->expectException(SchemaError::class);
        $this->expectExceptionMessage('选择项缺少有效主键');

        $this->dispatcher()->dispatch('executeAction', [
            'resource' => 'demo.notes',
            'action' => 'archive',
            'selection' => [['title' => 'Missing ID']],
        ]);
    }

    public function testRelationOptionsKeepSelectedItemsFirstWithoutDuplicates(): void
    {
        $runtime = $this->createMock(RuntimeEngineInterface::class);
        $runtime->expects(self::once())
            ->method('relationCandidates')
            ->willReturn([
                'selected' => [['value' => 2, 'label' => '已选择']],
                'results' => [
                    ['value' => 1, 'label' => '第一项'],
                    ['value' => 2, 'label' => '已选择'],
                ],
            ]);

        self::assertSame([
            ['value' => 2, 'label' => '已选择'],
            ['value' => 1, 'label' => '第一项'],
        ], $this->dispatcher($runtime)->dispatch('loadOptions', [
            'resource' => 'demo.notes',
            'field' => 'category_id',
            'values' => [2],
        ]));
    }

    private function dispatcher(?RuntimeEngineInterface $runtime = null): RuntimeOperationDispatcher
    {
        return new RuntimeOperationDispatcher(
            $runtime ?? $this->createMock(RuntimeEngineInterface::class),
            $this->createMock(TreeServiceInterface::class),
            $this->createMock(SchemaAuthorizationInterface::class),
            $this->createMock(SchemaSubjectContextInterface::class),
        );
    }
}
