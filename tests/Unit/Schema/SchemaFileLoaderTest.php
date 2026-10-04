<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Schema;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PTAdmin\Schema\SchemaFile\Loader;

final class SchemaFileLoaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-schema-loader-'.bin2hex(random_bytes(5));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->root);
    }

    public function testResourceFilesSupportStrictJsonAndJsonc(): void
    {
        $json = $this->root.'/demo.strict.schema.json';
        file_put_contents($json, json_encode($this->resource('demo.strict'), JSON_THROW_ON_ERROR));
        $jsonc = $this->root.'/demo.comments.schema.jsonc';
        file_put_contents($jsonc, <<<'JSONC'
        {
          // JSONC comment
          "resource": "demo.comments",
          "schema": {
            "name": "comments",
            "title": "Comments",
            "fields": [],
          },
        }
        JSONC);

        $loader = new Loader();
        self::assertSame('demo.strict', $loader->resourceFromFile($json)['resource']);
        self::assertSame('demo.comments', $loader->resourceFromFile($jsonc)['resource']);
    }

    public function testStrictJsonRejectsJsoncSyntax(): void
    {
        $path = $this->root.'/demo.invalid.schema.json';
        file_put_contents($path, <<<'JSON'
        {
          // Comments require the .jsonc extension.
          "resource": "demo.invalid",
          "schema": {"name": "invalid", "title": "Invalid", "fields": []}
        }
        JSON);

        $this->expectException(InvalidArgumentException::class);
        (new Loader())->resourceFromFile($path);
    }

    public function testResourceExtensionsRemainInSchemaWithExplicitFlowMetadata(): void
    {
        $definition = $this->resource('demo.extension');
        $definition['design_flow_id'] = 'demo:demo.schema';
        $definition['schema']['extensions'] = ['demo' => ['items' => [], 'enabled' => false, 'extra' => ['zero' => 0]]];
        $loaded = (new Loader())->loadJsonResource(json_encode($definition, JSON_THROW_ON_ERROR));
        self::assertSame('demo:demo.schema', $loaded['metadata']['design_flow_id']);
        self::assertSame($definition['schema']['extensions'], $loaded['schema']->toArray()['extensions']);
    }

    public function testOuterExtensionsAreRejectedInsteadOfSilentlyIgnored(): void
    {
        $definition = $this->resource('demo.extension');
        $definition['extensions'] = ['demo' => ['value' => true]];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('schema.extensions');
        (new Loader())->loadJsonResource(json_encode($definition, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function resource(string $resource): array
    {
        return [
            'resource' => $resource,
            'schema' => [
                'name' => substr($resource, strpos($resource, '.') + 1),
                'title' => 'Demo',
                'fields' => [],
            ],
        ];
    }
}
