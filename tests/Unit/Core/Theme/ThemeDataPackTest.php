<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Theme;

use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Theme\ThemeDataPackDescriptor;
use PTAdmin\Core\Theme\ThemeDataPackImportAdapterInterface;
use PTAdmin\Core\Theme\ThemeDataPackImportResult;
use PTAdmin\Core\Theme\ThemeDataPackImporter;
use PTAdmin\Core\Theme\ThemeDataPackRegistry;
use PTAdmin\Core\Theme\ThemeDataPackResource;
use PTAdmin\Core\Theme\ThemeDataPackValidator;
use PTAdmin\Core\Theme\ThemeException;
use PTAdmin\Core\Theme\ThemeManifestValidator;

final class ThemeDataPackTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-data-pack-'.bin2hex(random_bytes(5));
        mkdir($this->root.'/data', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testManifestDataPackIsValidatedAsARestrictedJsonContract(): void
    {
        file_put_contents($this->root.'/data/slots.json', json_encode([
            'records' => [[
                'resource' => 'cms.ad_slot',
                'key' => 'home.hero',
                'data' => ['key' => 'home.hero', 'title' => 'Hero'],
            ]],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->root.'/theme.json', json_encode([
            'code' => 'classic',
            'version' => '1.0.0',
            'name' => 'Classic',
            'data_packs' => [[
                'code' => 'cms-default-slots',
                'title' => 'CMS slots',
                'description' => 'Default slots',
                'optional' => true,
                'requires' => ['cms.ad_slot'],
                'file' => 'data/slots.json',
            ]],
        ], JSON_THROW_ON_ERROR));

        $descriptor = (new ThemeManifestValidator())->validateDirectory(
            $this->root,
            'classic',
            '1.0.0',
        );

        self::assertSame('cms-default-slots', $descriptor->manifest['data_packs'][0]['code']);
    }

    public function testDataPackRejectsMissingFileAndUnsafePath(): void
    {
        $validator = new ThemeDataPackValidator();
        $pack = ['code' => 'slots', 'title' => 'Slots', 'description' => '', 'optional' => true, 'requires' => ['cms.ad_slot'], 'file' => 'data/missing.json'];

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('data pack file is unavailable');
        $validator->validate([$pack], $this->root);
    }

    public function testUnavailableResourceDoesNotExecuteImport(): void
    {
        $pack = new ThemeDataPackDescriptor('slots', 'Slots', '', true, ['cms.ad_slot'], 'data/slots.json', $this->root.'/data/slots.json');
        file_put_contents($pack->path, json_encode(['records' => []], JSON_THROW_ON_ERROR));
        $importer = new ThemeDataPackImporter(new ThemeDataPackRegistry());

        $result = $importer->import($pack);

        self::assertSame('unavailable', $result->status);
        self::assertSame('resource_unavailable', $result->errors[0]['error_code']);
    }

    public function testImportValidatesStableKeyAndFieldTypesBeforeAdapter(): void
    {
        $pack = new ThemeDataPackDescriptor('slots', 'Slots', '', true, ['cms.ad_slot'], 'data/slots.json', $this->root.'/data/slots.json');
        file_put_contents($pack->path, json_encode(['records' => [[
            'resource' => 'cms.ad_slot',
            'key' => 'home.hero',
            'data' => ['key' => 'home.hero', 'title' => 123],
        ]]], JSON_THROW_ON_ERROR));
        $adapter = new class implements ThemeDataPackImportAdapterInterface {
            public bool $called = false;
            public function resource(string $resourceKey): ?ThemeDataPackResource
            {
                return $resourceKey === 'cms.ad_slot' ? new ThemeDataPackResource('cms.ad_slot', 'key', ['key' => 'string', 'title' => 'string']) : null;
            }
            public function import(string $resourceKey, array $records): ThemeDataPackImportResult
            {
                $this->called = true;
                return new ThemeDataPackImportResult('imported', created: count($records));
            }
        };
        $registry = new ThemeDataPackRegistry();
        $registry->register($adapter);
        $importer = new ThemeDataPackImporter($registry);

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('field type is invalid');
        try {
            $importer->import($pack);
        } finally {
            self::assertFalse($adapter->called);
        }
    }

    public function testImportDelegatesValidatedRecordsAndReturnsCounts(): void
    {
        $pack = new ThemeDataPackDescriptor('slots', 'Slots', '', true, ['cms.ad_slot'], 'data/slots.json', $this->root.'/data/slots.json');
        file_put_contents($pack->path, json_encode(['records' => [[
            'resource' => 'cms.ad_slot',
            'key' => 'home.hero',
            'data' => ['key' => 'home.hero', 'title' => 'Hero'],
        ]]], JSON_THROW_ON_ERROR));
        $adapter = new class implements ThemeDataPackImportAdapterInterface {
            public function resource(string $resourceKey): ?ThemeDataPackResource
            {
                return $resourceKey === 'cms.ad_slot' ? new ThemeDataPackResource('cms.ad_slot', 'key', ['key' => 'string', 'title' => 'string']) : null;
            }
            public function import(string $resourceKey, array $records): ThemeDataPackImportResult
            {
                return new ThemeDataPackImportResult('imported', created: count($records));
            }
        };
        $registry = new ThemeDataPackRegistry();
        $registry->register($adapter);

        $result = (new ThemeDataPackImporter($registry))->import($pack);

        self::assertSame('imported', $result->status);
        self::assertSame(1, $result->created);
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
