<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon\Frontend;

use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\Catalog\AddonCatalog;
use PTAdmin\Addon\Exception\AddonException;
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
            [['key' => 'demo-addon.page', 'owner_type' => 'addon', 'owner_code' => 'demo-addon', 'children' => []]],
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
            './plugins/demo-addon/index.html?v=1.0.0',
            $plugin['entries'][0]['entry'],
        );

        self::assertSame([], $registry->resolve([['key' => 'another.page']])['plugins']);
        self::assertCount(1, $registry->resolve([[
            'key' => 'demo-addon.page', 'owner_type' => 'addon', 'owner_code' => 'demo-addon',
        ]])['plugins']);
        self::assertSame([], $registry->resolve([['key' => 'demo-addon.page', 'type' => 'page']])['plugins']);
        foreach (['group', 'schema', 'page'] as $type) {
            self::assertCount(1, $registry->resolve([[
                'key' => 'independent-navigation-key',
                'type' => $type,
                'owner_type' => 'addon',
                'owner_code' => 'demo-addon',
            ]])['plugins']);
        }
    }

    public function testRegistryRequiresLowercaseAddonDirectoryMatchingManifestCode(): void
    {
        $this->writeAddon('iframe', 'index.html');
        rename($this->addonsPath.'/demo-addon', $this->addonsPath.'/cms');
        rename($this->publicPath.'/admintest/plugins/demo-addon', $this->publicPath.'/admintest/plugins/cms');

        $manifestPath = $this->addonsPath.'/cms/manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['code'] = 'cms';
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $descriptorPath = $this->addonsPath.'/cms/frontend/frontend.json';
        $descriptor = json_decode((string) file_get_contents($descriptorPath), true, 512, JSON_THROW_ON_ERROR);
        $descriptor['entries'][0]['key'] = 'cms.page';
        file_put_contents($descriptorPath, json_encode($descriptor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $plugin = $this->registry(new TrustPolicy())->resolve([[
            'key' => 'cms.page',
            'type' => 'page',
            'owner_type' => 'addon',
            'owner_code' => 'cms',
        ]])['plugins'][0];

        self::assertSame('cms', $plugin['code']);
        self::assertSame('/ptadmin/cms', $plugin['api_base']);
        self::assertSame('./plugins/cms/index.html?v=1.0.0', $plugin['entries'][0]['entry']);
    }

    public function testTrustedLocalEntryNeedsExplicitPolicyAndIsNeverDowngraded(): void
    {
        $this->writeAddon('trusted', 'frontend.mjs', true);

        $denied = $this->registry(new TrustPolicy([], [], false, 'testing'))->resolve(
            $this->addonMenu(),
        );
        self::assertSame([], $denied['plugins']);
        self::assertSame('trusted_publisher_denied', $denied['errors'][0]['error_code']);

        $allowed = $this->registry(new TrustPolicy([], [], true, 'testing'))->resolve(
            $this->addonMenu(),
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
        file_put_contents($this->publicPath.'/admintest/plugins/demo-addon/index.html', 'tampered');

        $resolved = $this->registry(new TrustPolicy())->resolve(
            $this->addonMenu(),
        );

        self::assertCount(1, $resolved['plugins']);
        self::assertSame([], $resolved['errors']);
    }

    public function testDevelopmentDescriptorOverridesOnlyTheCodeSource(): void
    {
        $this->writeAddon('iframe', 'index.html', false, false, true);
        $development = new FrontendDevelopmentStore('testing');

        $plugin = $this->registry(new TrustPolicy(), $development)
            ->resolve($this->addonMenu())['plugins'][0];

        self::assertSame('/ptadmin/demo-addon', $plugin['api_base']);
        self::assertSame(['schema.runtime'], $plugin['entries'][0]['capabilities']);
        self::assertSame('development', $plugin['entries'][0]['development']['load_mode']);
        self::assertSame('http://127.0.0.1:5173', $plugin['entries'][0]['development']['allowed_origin']);
        self::assertSame('cms-local', $plugin['entries'][0]['development']['dev_revision']);
        self::assertSame('available', $plugin['entries'][0]['development']['status']);
    }

    public function testInlineDevelopmentDescriptorIsReturnedInLocalSessionWithoutRegistrationFile(): void
    {
        $this->writeAddon('trusted', 'frontend.mjs', true, false, true);
        $this->remove($this->publicPath.'/admintest/plugins/demo-addon');
        $plugin = $this->registry(
            new TrustPolicy([], [], true, 'testing'),
            new FrontendDevelopmentStore('testing'),
        )->resolve($this->addonMenu())['plugins'][0];

        self::assertSame('http://127.0.0.1:5173/src/frontend.ts', $plugin['entries'][0]['development']['entry_url']);
        self::assertSame('cms-local', $plugin['entries'][0]['development']['dev_revision']);
        self::assertSame('available', $plugin['entries'][0]['development']['status']);
    }

    public function testInlineDevelopmentDescriptorIsIgnoredOutsideLocalEnvironments(): void
    {
        $this->writeAddon('trusted', 'frontend.mjs', true, false, true);
        $plugin = $this->registry(
            new TrustPolicy([], [], true, 'testing'),
            new FrontendDevelopmentStore('production'),
        )->resolve($this->addonMenu())['plugins'][0];

        self::assertArrayNotHasKey('development', $plugin['entries'][0]);
    }

    public function testInvalidInlineDevelopmentDescriptorIsReportedPerPlugin(): void
    {
        $this->writeAddon('iframe', 'index.html', false, false, true);
        $path = $this->addonsPath.'/demo-addon/frontend/frontend.json';
        $descriptor = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $descriptor['development'] = 'invalid';
        file_put_contents($path, json_encode($descriptor, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        $resolved = $this->registry(
            new TrustPolicy(),
            new FrontendDevelopmentStore('testing'),
        )->resolve($this->addonMenu());

        self::assertSame([], $resolved['plugins']);
        self::assertSame('frontend_development_invalid', $resolved['errors'][0]['error_code']);
    }

    public function testRegistryExposesValidatedTrustedDesignFlows(): void
    {
        $this->writeAddon('trusted', 'frontend.mjs', true, true);

        $plugin = $this->registry(new TrustPolicy([], [], true, 'testing'))
            ->resolve($this->addonMenu())['plugins'][0];

        self::assertSame('demo-addon.schema', $plugin['design_flows'][0]['id']);
        self::assertSame('schema.resources.update', $plugin['design_flows'][0]['permission']);
        self::assertSame(
            ['frontend_contribution' => ['contribution' => 'demo-addon.review']],
            $plugin['design_flows'][0]['steps'][0]['mount'],
        );
    }

    public function testDesignFlowExtensionDeclarationsSurviveSessionResolution(): void
    {
        $this->writeAddon('trusted', 'frontend.mjs', true, true);
        $path = $this->addonsPath.'/demo-addon/frontend/frontend.json';
        $descriptor = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $descriptor['design_flows'][0]['extension_namespace'] = 'demo';
        $descriptor['design_flows'][0]['steps'][0]['extension_paths'] = ['profile', 'map'];
        file_put_contents($path, json_encode($descriptor, JSON_THROW_ON_ERROR));
        $registry = $this->registry(new TrustPolicy([], [], true, 'testing'));
        $flow = $registry->resolve($this->addonMenu())['plugins'][0]['design_flows'][0];
        self::assertSame('demo', $flow['extension_namespace']);
        self::assertSame(['profile', 'map'], $flow['steps'][0]['extension_paths']);
        self::assertSame($flow, $registry->designFlow('demo-addon:demo-addon.schema'));
        self::assertNull($registry->designFlow('demo-addon:missing'));
    }

    public function testOverlappingStepExtensionPathsAreRejected(): void
    {
        $this->writeAddon('trusted', 'frontend.mjs', true, true);
        $path = $this->addonsPath.'/demo-addon/frontend/frontend.json';
        $descriptor = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $flow = &$descriptor['design_flows'][0];
        $flow['extension_namespace'] = 'demo';
        $flow['steps'][0]['extension_paths'] = ['mapping'];
        $flow['steps'][] = ['id' => 'details', 'title' => '详情', 'extension_paths' => ['mapping.title'], 'mount' => $flow['steps'][0]['mount']];
        file_put_contents($path, json_encode($descriptor, JSON_THROW_ON_ERROR));
        $registry = $this->registry(new TrustPolicy([], [], true, 'testing'));
        $resolved = $registry->resolve($this->addonMenu());
        self::assertSame([], $resolved['plugins']);
        self::assertSame('frontend_design_flows_invalid', $resolved['errors'][0]['error_code']);
        self::assertNull($registry->designFlow('demo-addon:demo-addon.schema'));
    }

    public function testSynchronizationUsesInactiveDesignFlowsWithoutExposingSessionEntries(): void
    {
        $this->writeAddon('trusted', 'frontend.mjs', true, true);
        $directory = $this->addonsPath.'/demo-addon';
        $manifest = (new ManifestVerifier())->verifyRuntime($directory);
        $lifecycle = new LifecycleStore($this->lifecyclePath);
        $lifecycle->restore('demo-addon', ['state' => 'inactive', 'disabled_by_user' => true]);
        $registry = $this->registry(new TrustPolicy([], [], true, 'testing'));
        self::assertNull($registry->designFlow('demo-addon:demo-addon.schema'));

        $result = $registry->withAddonDesignFlows($directory, $manifest, function () use ($registry): string {
            self::assertSame('demo-addon.schema', $registry->designFlow('demo-addon:demo-addon.schema')['id']);
            self::assertNull($registry->designFlow('demo-addon:missing'));
            self::assertSame([], $registry->resolve($this->addonMenu())['plugins']);
            return '已同步';
        });

        self::assertSame('已同步', $result);
        self::assertNull($registry->designFlow('demo-addon:demo-addon.schema'));
        self::assertSame('inactive', $lifecycle->read('demo-addon')['state']);
        self::assertTrue($lifecycle->read('demo-addon')['disabled_by_user']);
    }

    public function testNestedSynchronizationRestoresDeclarationsAfterFailure(): void
    {
        $this->writeAddon('trusted', 'frontend.mjs', true, true);
        $directory = $this->addonsPath.'/demo-addon';
        $manifest = (new ManifestVerifier())->verifyRuntime($directory);
        (new LifecycleStore($this->lifecyclePath))->restore('demo-addon', ['state' => 'inactive']);
        $registry = $this->registry(new TrustPolicy([], [], true, 'testing'));
        $registry->withAddonDesignFlows($directory, $manifest, function () use ($registry, $directory, $manifest): void {
            $path = $directory.'/frontend/frontend.json';
            $descriptor = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $descriptor['design_flows'][0]['id'] = 'demo-addon.changed';
            file_put_contents($path, json_encode($descriptor, JSON_THROW_ON_ERROR));
            try {
                $registry->withAddonDesignFlows($directory, $manifest, function () use ($registry): void {
                    self::assertNotNull($registry->designFlow('demo-addon:demo-addon.changed'));
                    self::assertNull($registry->designFlow('demo-addon:demo-addon.schema'));
                    throw new \RuntimeException('同步失败');
                });
                self::fail('同步异常必须向调用方传播。');
            } catch (\RuntimeException $exception) {
                self::assertSame('同步失败', $exception->getMessage());
            }
            self::assertNotNull($registry->designFlow('demo-addon:demo-addon.schema'));
            self::assertNull($registry->designFlow('demo-addon:demo-addon.changed'));
        });
        self::assertNull($registry->designFlow('demo-addon:demo-addon.schema'));
        self::assertNull($registry->designFlow('demo-addon:demo-addon.changed'));
    }

    public function testInvalidSynchronizationDeclarationCannotRunOrLeak(): void
    {
        $this->writeAddon('trusted', 'frontend.mjs', true, true);
        $directory = $this->addonsPath.'/demo-addon';
        $manifest = (new ManifestVerifier())->verifyRuntime($directory);
        (new LifecycleStore($this->lifecyclePath))->restore('demo-addon', ['state' => 'inactive']);
        $path = $directory.'/frontend/frontend.json';
        $descriptor = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $descriptor['design_flows'][0]['steps'][0]['mount'] = ['frontend_contribution' => ['contribution' => 'another-addon.invalid']];
        file_put_contents($path, json_encode($descriptor, JSON_THROW_ON_ERROR));
        $registry = $this->registry(new TrustPolicy([], [], true, 'testing'));
        try {
            $registry->withAddonDesignFlows($directory, $manifest, static function (): void {
                self::fail('无效声明不能开始资源同步。');
            });
            self::fail('无效声明必须拒绝。');
        } catch (AddonException $exception) {
            self::assertSame('frontend_design_flows_invalid', $exception->errorCode);
        }
        self::assertNull($registry->designFlow('demo-addon:demo-addon.schema'));
    }

    public function testPublishedBuildStylesUseContentRevisionsAndRejectUnsafeOrMissingFiles(): void
    {
        $this->writeAddon('trusted', 'frontend.mjs', true);
        $source = $this->addonsPath.'/demo-addon/frontend/dist';
        mkdir($source.'/.vite');
        file_put_contents($source.'/page.css', '.page { color: red }');
        $build = ['schema' => 'sparq.frontend-build/v1', 'entry' => 'frontend.mjs', 'styles' => ['page.css']];
        $publish = function (array $descriptor) use ($source): array {
            file_put_contents($source.'/.vite/sparq-root.json', json_encode($descriptor, JSON_THROW_ON_ERROR));
            (new FrontendPublisher($this->publicPath))->publish('admintest', 'demo-addon', $source);
            return $this->registry(new TrustPolicy([], [], true, 'testing'))->resolve($this->addonMenu());
        };
        $resolved = $publish($build);
        self::assertSame([], $resolved['errors']);
        $styles = $resolved['plugins'][0]['entries'][0]['styles'];
        self::assertSame(['./plugins/demo-addon/page.css?v='.hash_file('sha256', $source.'/page.css')], $styles);
        file_put_contents($source.'/page.css', '.page { color: blue }');
        self::assertNotSame($styles, $publish($build)['plugins'][0]['entries'][0]['styles']);
        foreach (['../page.css', '/page.css', 'https://example.test/page.css', 'page.css?x=1'] as $invalid) {
            $result = $publish(array_replace($build, ['styles' => [$invalid]]));
            self::assertSame([], $result['plugins']);
            self::assertSame('frontend_style_invalid', $result['errors'][0]['error_code']);
        }
        self::assertSame('frontend_style_unavailable', $publish(array_replace($build, ['styles' => ['missing.css']]))['errors'][0]['error_code']);
        self::assertSame('frontend_build_invalid', $publish(array_replace($build, ['entry' => 'other.mjs']))['errors'][0]['error_code']);
        self::assertSame('frontend_build_invalid', $publish(array_replace($build, ['schema' => 'unknown']))['errors'][0]['error_code']);
        $publish($build);
        $published = $this->publicPath.'/admintest/plugins/demo-addon';
        unlink($published.'/page.css');
        symlink($source.'/page.css', $published.'/page.css');
        $escaped = $this->registry(new TrustPolicy([], [], true, 'testing'))->resolve($this->addonMenu());
        self::assertSame('frontend_style_unavailable', $escaped['errors'][0]['error_code']);
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
            $development ?? new FrontendDevelopmentStore('production'),
            $this->addonsPath,
            $this->publicPath,
            'admintest',
            'ptadmin',
            ['v1'],
            ['admin-v1'],
            ['schema.runtime'],
        );
    }

    /** @return list<array<string, mixed>> */
    private function addonMenu(): array
    {
        return [[
            'key' => 'demo-addon.page',
            'type' => 'page',
            'owner_type' => 'addon',
            'owner_code' => 'demo-addon',
        ]];
    }

    private function writeAddon(
        string $sandbox,
        string $entry,
        bool $localTrusted = false,
        bool $withDesignFlow = false,
        bool $withDevelopment = false,
    ): void
    {
        $directory = $this->addonsPath.'/demo-addon';
        mkdir($directory.'/frontend/dist', 0700, true);
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
        if ($withDevelopment) {
            $descriptor['development'] = [
                'entry_url' => $sandbox === 'iframe'
                    ? 'http://127.0.0.1:5173/index.html'
                    : 'http://127.0.0.1:5173/src/frontend.ts',
                'sandbox' => $sandbox,
                'dev_revision' => 'cms-local',
                'hmr' => true,
                'source_maps' => true,
            ];
        }
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
            $directory.'/frontend/frontend.json',
            json_encode($descriptor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );
        file_put_contents($directory.'/frontend/dist/'.$entry, $sandbox === 'iframe' ? '<!doctype html>' : 'export default {}');
        $manifest = [
            'code' => 'demo-addon',
            'version' => '1.0.0',
            'sandbox' => $sandbox,
            'files' => ['frontend/dist/'.$entry, 'frontend/frontend.json'],
        ];
        if ($localTrusted) {
            $manifest['publisher'] = 'local:test';
        }
        file_put_contents(
            $directory.'/manifest.json',
            json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );

        (new FrontendPublisher($this->publicPath))->publish('admintest', 'demo-addon', $directory.'/frontend/dist');
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
