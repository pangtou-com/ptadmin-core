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

    public function testSiteConfigurationIncludesPublicIdentityAndRecordFields(): void
    {
        $definition = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/resources/config.site.json'), true, 512, JSON_THROW_ON_ERROR);
        $fields = array_column($definition['schema']['fields'], null, 'name');

        foreach (['title', 'url', 'site_keywords', 'site_description', 'site_logo', 'site_favicon', 'copyright', 'statistics_code', 'icp_number', 'icp_record_url', 'police_record_number', 'police_record_url'] as $name) {
            self::assertArrayHasKey($name, $fields);
        }
        self::assertArrayNotHasKey('site_name', $fields);
        self::assertArrayNotHasKey('contact_email', $fields);
        self::assertSame('public', $definition['exposure']);
        self::assertTrue($fields['title']['required']);
        self::assertTrue($fields['url']['required']);
        foreach (['site_keywords', 'site_favicon', 'copyright', 'statistics_code', 'icp_number', 'police_record_number', 'police_record_url'] as $name) {
            self::assertSame('', $fields[$name]['default']);
        }
        self::assertSame('https://beian.miit.gov.cn/', $fields['icp_record_url']['default']);

        $nodes = array_column($definition['schema']['views']['forms']['default']['children'], null, 'field');
        self::assertSame(array_keys($fields), array_keys($nodes));
        self::assertSame('textarea', $nodes['site_description']['component']);
        self::assertSame('textarea', $nodes['statistics_code']['component']);
        foreach (['site_logo', 'site_favicon'] as $name) {
            self::assertSame('image-upload', $nodes[$name]['component']);
            self::assertSame('url', $nodes[$name]['props']['valueMode']);
            self::assertSame(1, $nodes[$name]['props']['limit']);
        }
        foreach (['site_logo', 'site_favicon', 'icp_number', 'icp_record_url', 'police_record_number', 'police_record_url'] as $name) {
            self::assertSame(12, $nodes[$name]['span']);
        }
    }

    public function testMailConfigurationOnlyDeclaresSmtpSettings(): void
    {
        $definition = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/resources/config.mail.json'), true, 512, JSON_THROW_ON_ERROR);
        $fields = array_column($definition['schema']['fields'], null, 'name');

        self::assertArrayNotHasKey('mailer', $fields);
        foreach (['host', 'port', 'encryption', 'username', 'password', 'from_address', 'from_name'] as $name) {
            self::assertArrayHasKey($name, $fields);
        }
    }

    public function testUploadConfigurationDeclaresPolicyInsteadOfStorageDirectories(): void
    {
        $definition = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/resources/config.upload.json'), true, 512, JSON_THROW_ON_ERROR);
        $fields = array_column($definition['schema']['fields'], null, 'name');

        self::assertSame([
            'enabled',
            'max_file_size_kilobytes',
            'chunk_enabled',
            'allowed_extensions',
            'chunk_threshold_kilobytes',
            'chunk_concurrency',
            'chunk_retry_count',
        ], array_keys($fields));
        foreach (['public_disk', 'private_disk', 'public_directory', 'private_directory', 'max_upload_kilobytes'] as $legacyField) {
            self::assertArrayNotHasKey($legacyField, $fields);
        }

        self::assertSame('boolean', $fields['enabled']['type']);
        self::assertSame('boolean', $fields['chunk_enabled']['type']);
        self::assertIsString($fields['allowed_extensions']['default']);
        self::assertSame('textarea', array_column($definition['schema']['views']['forms']['default']['children'], null, 'field')['allowed_extensions']['component']);
        self::assertSame('number', array_column($definition['schema']['views']['forms']['default']['children'], null, 'field')['chunk_retry_count']['component']);
    }
}
