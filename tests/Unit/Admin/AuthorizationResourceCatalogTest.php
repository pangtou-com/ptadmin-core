<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PTAdmin\Admin\Auth\AuthorizationResourceCatalog;

final class AuthorizationResourceCatalogTest extends TestCase
{
    public function testCapabilitiesComeOnlyFromTheResourceDefinition(): void
    {
        $catalog = new AuthorizationResourceCatalog();

        self::assertSame(['abilities' => [], 'scopes' => []], $catalog->capabilities([
            'type' => 'group',
            'meta_json' => json_encode(['abilities' => ['view']], JSON_THROW_ON_ERROR),
        ]));
        self::assertSame(['abilities' => [], 'scopes' => []], $catalog->capabilities([
            'type' => 'page',
            'meta_json' => json_encode([], JSON_THROW_ON_ERROR),
        ]));
        self::assertSame(['abilities' => ['view', 'publish'], 'scopes' => ['all', 'self']], $catalog->capabilities([
            'type' => 'schema',
            'meta_json' => json_encode([
                'abilities' => ['view', 'publish', 'view'],
                'scopes' => ['all', 'self', 'all'],
            ], JSON_THROW_ON_ERROR),
        ]));
    }

    public function testSchemaTargetDoesNotGenerateUndeclaredCapabilities(): void
    {
        $catalog = new AuthorizationResourceCatalog();

        self::assertSame(['abilities' => [], 'scopes' => []], $catalog->capabilities([
            'type' => 'schema',
            'meta_json' => json_encode(['target_resource_key' => 'cms.articles'], JSON_THROW_ON_ERROR),
        ]));
    }
}
