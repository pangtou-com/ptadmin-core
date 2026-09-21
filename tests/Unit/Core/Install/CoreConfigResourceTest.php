<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Install;

use PHPUnit\Framework\TestCase;
use PTAdmin\Schema\Definition\Schema;

final class CoreConfigResourceTest extends TestCase
{
    public function testBundledConfigurationDefinitionsAreValidJsonSchemas(): void
    {
        $paths = glob(dirname(__DIR__, 4).'/resources/config.*.json') ?: [];
        sort($paths, SORT_STRING);

        self::assertSame(['config.mail.json', 'config.site.json', 'config.upload.json'], array_map('basename', $paths));

        $keys = [];
        foreach ($paths as $path) {
            $definition = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($definition);
            self::assertArrayHasKey('group_code', $definition);
            self::assertArrayHasKey('config_key', $definition);
            self::assertArrayHasKey('schema', $definition);
            self::assertSame([], (new Schema((array) $definition['schema']))->validate(), $path);
            $keys[] = $definition['config_key'];
        }

        self::assertSame(['mail', 'site', 'upload'], $keys);
    }
}
