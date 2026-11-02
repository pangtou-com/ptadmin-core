<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Template\Directive;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PTAdmin\Template\Directive\DirectiveDocumentation;
use PTAdmin\Template\Directive\DirectiveRegistry;

final class DirectiveDocumentationTest extends TestCase
{
    public function testRegistryMetadataProducesDeterministicHandlerFreeDocument(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->begin('demo');
        $registration->define('items')->loop(static fn (): array => [])
            ->description('读取条目。')
            ->parameter('limit', type: 'integer', default: 10, min: 1, max: 100, description: '返回数量。')
            ->returns(['fields' => ['title' => ['type' => 'string', 'description' => '标题。']]])
            ->examples(['@pt:demo_items(limit=5,as=item){$item.title}@pt:end'])
            ->commit();
        $registration->commit();

        $document = DirectiveDocumentation::fromRegistry($registry, 'demo', '1.2.0');
        self::assertNotNull($document);
        $first = DirectiveDocumentation::encode($document);
        $second = DirectiveDocumentation::encode($document);
        self::assertSame($first, $second);
        self::assertStringNotContainsString('handler', $first);
        self::assertSame(
            $document,
            DirectiveDocumentation::decode($first, 'demo', '1.2.0'),
        );
    }

    public function testEmptyNamespaceDoesNotProduceDocument(): void
    {
        self::assertNull(DirectiveDocumentation::fromRegistry(new DirectiveRegistry(), 'demo', '1.0.0'));
    }

    public function testDocumentIdentityAndUnknownFieldsAreRejected(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->begin('demo');
        $registration->define('text')->output(static fn (): string => 'ok')->commit();
        $registration->commit();
        $document = DirectiveDocumentation::fromRegistry($registry, 'demo', '1.0.0');
        $document['directives']['demo_text']['handler'] = 'forbidden';

        $this->expectException(InvalidArgumentException::class);
        DirectiveDocumentation::encode($document);
    }

    public function testUnknownNestedReturnFieldsAreRejected(): void
    {
        $registry = new DirectiveRegistry();
        $registration = $registry->begin('demo');
        $registration->define('items')->loop(static fn (): array => [])->returns([
            'fields' => ['title' => ['type' => 'string', 'description' => '标题。']],
        ])->commit();
        $registration->commit();
        $document = DirectiveDocumentation::fromRegistry($registry, 'demo', '1.0.0');
        $document['directives']['demo_items']['returns']['fields']['title']['handler'] = 'forbidden';

        $this->expectException(InvalidArgumentException::class);
        DirectiveDocumentation::encode($document);
    }
}
