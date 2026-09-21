<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon\Frontend;

use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Addon\Frontend\FrontendPublisher;
use PTAdmin\Addon\Frontend\FrontendRegistry;
use PTAdmin\Addon\Frontend\FrontendDevelopmentStore;
use PTAdmin\Addon\Lifecycle\LifecycleStore;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Addon\Manifest\TrustPolicy;

final class FrontendRegistryTest extends TestCase
{
    private string $root;
    private string $addonsPath;
    private string $publicPath;
    private string $lifecyclePath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-addon-frontends-'.bin2hex(random_bytes(5));
        $this->addonsPath = $this->root.'/addons';
        $this->publicPath = $this->root.'/public';
        $this->lifecyclePath = $this->root.'/lifecycle';
        mkdir($this->addonsPath, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testRegistryBuildsRelativeVersionedEntriesAndFiltersBySessionAccess(): void
    {
        $this->writeAddon('iframe', 'index.html');
        $registry = $this->registry(new TrustPolicy());
        $resolved = $registry->resolve(
            [['key' => 'demo-addon.page', 'children' => []]],
        );

        self::assertSame([], $resolved['errors']);
        self::assertCount(1, $resolved['plugins']);
        $plugin = $resolved['plugins'][0];
        self::assertSame('demo-addon', $plugin['code']);
        self::assertTrue($plugin['enabled']);
        self::assertSame('v1', $plugin['entries'][0]['host_api']);
        self::assertSame('/ptadmin/demo-addon', $plugin['api_base']);
        self::assertSame(['schema.runtime'], $plugin['entries'][0]['capabilities']);
        self::assertSame(
            './modules/demo-addon/index.html?v=1.0.0',
            $plugin['entries'][0]['entry'],
        );

        self::assertSame([], $registry->resolve([['key' => 'another.page']])['plugins']);
        self::assertCount(1, $registry->resolve([['key' => 'demo-addon.page']])['plugins']);
    }

    public function testTrustedLocalEntryNeedsExplicitPolicyAndIsNeverDowngraded(): void
    {
        $this->writeAddon('trusted', 'frontend.mjs', true);

        $denied = $this->registry(new TrustPolicy([], [], false, 'testing'))->resolve(
            [['key' => 'demo-addon.page']],
        );
        self::assertSame([], $denied['plugins']);
        self::assertSame('trusted_publisher_denied', $denied['errors'][0]['error_code']);

        $allowed = $this->registry(new TrustPolicy([], [], true, 'testing'))->resolve(
            [['key' => 'demo-addon.page']],
        );
        self::assertCount(1, $allowed['plugins']);
        $entry = $allowed['plugins'][0]['entries'][0];
        self::assertSame('trusted', $entry['sandbox']);
        self::assertSame('admin-v1', $entry['runtime_profile']);
        self::assertFalse($entry['trust']['verified']);
        self::assertSame('local', $entry['trust']['mode']);
    }

    public function testRegistryDoesNotPerformPackageIntegrityChecksAtRuntime(): void
    {
        $this->writeAddon('iframe', 'index.html');
        file_put_contents($this->publicPath.'/admintest/modules/demo-addon/index.html', 'tampered');

        $resolved = $this->registry(new TrustPolicy())->resolve(
            [['key' => 'demo-addon.page']],
        );

        self::assertCount(1, $resolved['plugins']);
        self::assertSame([], $resolved['errors']);
    }

    public function testDevelopmentDescriptorOverridesOnlyTheCodeSource(): void
    {
        $this->writeAddon('iframe', 'index.html');
        $development = new FrontendDevelopmentStore($this->root.'/dev', 'testing');
        $development->register('demo-addon', 'http://127.0.0.1:5174/index.html', 'iframe', 'dev-4');

        $plugin = $this->registry(new TrustPolicy(), $development)
            ->resolve([['key' => 'demo-addon.page']])['plugins'][0];

        self::assertSame('/ptadmin/demo-addon', $plugin['api_base']);
        self::assertSame(['schema.runtime'], $plugin['entries'][0]['capabilities']);
        self::assertSame('development', $plugin['entries'][0]['development']['load_mode']);
        self::assertSame('http://127.0.0.1:5174', $plugin['entries'][0]['development']['allowed_origin']);
        self::assertSame('dev-4', $plugin['entries'][0]['development']['dev_revision']);
        self::assertSame('available', $plugin['entries'][0]['development']['status']);
    }

    public function testRegistryExposesValidatedTrustedDesignFlows(): void
    {
        $this->writeAddon('trusted', 'frontend.mjs', true, true);

        $plugin = $this->registry(new TrustPolicy([], [], true, 'testing'))
            ->resolve([['key' => 'demo-addon.page']])['plugins'][0];

        self::assertSame('demo-addon.schema', $plugin['design_flows'][0]['id']);
        self::assertSame('schema.resources.update', $plugin['design_flows'][0]['permission']);
        self::assertSame(
            ['frontend_contribution' => ['contribution' => 'demo-addon.review']],
            $plugin['design_flows'][0]['steps'][0]['mount'],
        );
    }

    public function testInvalidDevelopmentRegistrationIsReportedPerPlugin(): void
    {
        $this->writeAddon('iframe', 'index.html');
        mkdir($this->root.'/dev', 0700, true);
        file_put_contents($this->root.'/dev/demo-addon.json', '{invalid');

        $resolved = $this->registry(
            new TrustPolicy(),
            new FrontendDevelopmentStore($this->root.'/dev', 'testing'),
        )->resolve([['key' => 'demo-addon.page']]);

        self::assertSame([], $resolved['plugins']);
        self::assertSame('frontend_development_registration_invalid', $resolved['errors'][0]['error_code']);
    }

    private function registry(TrustPolicy $trustPolicy, ?FrontendDevelopmentStore $development = null): FrontendRegistry
    {
        $lifecycle = new LifecycleStore($this->lifecyclePath);
        $verifier = new ManifestVerifier('0.1.0', 1, $trustPolicy);

        return new FrontendRegistry(
            new AddonCatalog($this->addonsPath, $verifier, $lifecycle, false),
            $verifier,
            $trustPolicy,
            $lifecycle,
            $development ?? new FrontendDevelopmentStore($this->root.'/dev', 'production'),
            $this->addonsPath,
            $this->publicPath,
            'admintest',
            'ptadmin',
            ['v1'],
            ['admin-v1'],
            ['schema.runtime'],
        );
    }

    private function writeAddon(
        string $sandbox,
        string $entry,
        bool $localTrusted = false,
        bool $withDesignFlow = false,
    ): void
    {
        $directory = $this->addonsPath.'/demo-addon';
        mkdir($directory.'/Frontend/dist', 0700, true);
        $descriptor = [
            'host_api' => 'v1',
            'runtime_profile' => 'admin-v1',
            'entries' => [[
                'key' => 'demo-addon.page',
                'title' => 'Demo Page',
                'entry' => $entry,
                'sandbox' => $sandbox,
                'capabilities' => ['schema.runtime'],
            ]],
        ];
        if ($withDesignFlow) {
            $descriptor['design_flows'] = [[
                'id' => 'demo-addon.schema',
                'target' => 'schema.designer',
                'permission' => 'schema.resources.update',
                'steps' => [[
                    'id' => 'review',
                    'title' => '发布检查',
                    'mount' => [
                        'frontend_contribution' => ['contribution' => 'demo-addon.review'],
                    ],
                ]],
            ]];
        }
        file_put_contents(
            $directory.'/Frontend/frontend.json',
            json_encode($descriptor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );
        file_put_contents($directory.'/Frontend/dist/'.$entry, $sandbox === 'iframe' ? '<!doctype html>' : 'export default {}');
        $manifest = [
            'code' => 'demo-addon',
            'version' => '1.0.0',
            'sandbox' => $sandbox,
            'files' => ['Frontend/dist/'.$entry, 'Frontend/frontend.json'],
        ];
        if ($localTrusted) {
            $manifest['publisher'] = 'local:test';
        }
        file_put_contents(
            $directory.'/manifest.json',
            json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );

        (new FrontendPublisher($this->publicPath))->publish('admintest', 'demo-addon', $directory.'/Frontend/dist');
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if (in_array($name, ['.', '..'], true)) {
                continue;
            }
            $entry = $path.'/'.$name;
            is_dir($entry) ? $this->remove($entry) : @unlink($entry);
        }
        @rmdir($path);
    }
}
