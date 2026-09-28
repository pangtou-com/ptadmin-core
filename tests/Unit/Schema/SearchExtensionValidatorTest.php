<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use PTAdmin\Schema\Definition\Schema;

final class SearchExtensionValidatorTest extends TestCase
{
    public function testCompositeSearchRejectsInvalidTargets(): void
    {
        $definition = [
            'name' => 'accounts', 'title' => '账号',
            'fields' => [
                ['name' => 'keyword', 'type' => 'string', 'persisted' => false, 'searchable' => true,
                    'extensions' => ['search' => ['fields' => ['username']]]],
                ['name' => 'username', 'type' => 'string', 'persisted' => true, 'searchable' => true],
            ],
        ];
        self::assertSame([], (new Schema($definition))->validate());

        foreach ([[], ['missing'], ['keyword'], [42], 'username'] as $targets) {
            $invalid = $definition;
            $invalid['fields'][0]['extensions']['search']['fields'] = $targets;
            self::assertNotEmpty((new Schema($invalid))->validate());
        }
        foreach ([['persisted' => false], ['searchable' => false], ['type' => 'integer'], ['multiple' => true],
            ['extensions' => ['secret' => ['storage_mode' => 'encrypted']]]] as $overrides) {
            $invalid = $definition;
            $invalid['fields'][1] = array_replace($invalid['fields'][1], $overrides);
            self::assertNotEmpty((new Schema($invalid))->validate());
        }
    }
}
