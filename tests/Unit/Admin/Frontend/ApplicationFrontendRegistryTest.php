<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Admin\Frontend;

use PTAdmin\Admin\Frontend\ApplicationFrontendRegistry;
use PTAdmin\Addon\Frontend\FrontendPublisher;
use PTAdmin\Addon\Frontend\FrontendDevelopmentStore;
use PHPUnit\Framework\TestCase;

final class ApplicationFrontendRegistryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-app-frontend-'.bin2hex(random_bytes(5));
        mkdir($this->root.'/frontend', 0700, true);
        mkdir($this->root.'/public', 0700, true);
        file_put_contents($this->root.'/frontend/_plugin-vue_export-helper.js', 'export default {}');
        file_put_contents($this->root.'/frontend/app.mjs', 'export default function register() {}');
        file_put_contents($this->root.'/frontend/frontend.json', json_encode([
            'version' => '1.0.0',
            'host_api' => 'v1',
            'runtime_profile' => 'admin-v1',
            'entries' => [[
                'key' => 'app.users',
                'title' => '应用用户',
                'entry' => 'app.mjs',
                'sandbox' => 'trusted',
                'capabilities' => ['context'],
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testPublishedApplicationModuleRequiresAndReturnsVerifiedManifest(): void
    {
        $publisher = new FrontendPublisher($this->root.'/public');
        $publisher->publishApplication('admintest', $this->root.'/frontend');
        $registry = new ApplicationFrontendRegistry(
            $this->root,
            $this->root.'/public',
            'admintest',
            'ptadmin',
            ['v1'],
            ['admin-v1'],
            ['context'],
            new FrontendDevelopmentStore('production'),
        );

        $resolved = $registry->resolve([[
            'key' => 'app.users',
            'type' => 'page',
            'owner_type' => 'app',
            'owner_code' => 'host',
        ]]);

        self::assertSame([], $resolved['errors']);
        self::assertSame('app', $resolved['modules'][0]['owner_type']);
        self::assertSame('./modules/app/app.mjs?v=1.0.0', $resolved['modules'][0]['entries'][0]['entry']);
        self::assertTrue($resolved['modules'][0]['entries'][0]['trust']['verified']);
    }

    public function testTamperedApplicationArtifactIsRejected(): void
    {
        $publisher = new FrontendPublisher($this->root.'/public');
        $publisher->publishApplication('admintest', $this->root.'/frontend');
        file_put_contents($this->root.'/public/admintest/modules/app/app.mjs', 'tampered');
        $registry = new ApplicationFrontendRegistry($this->root, $this->root.'/public', 'admintest', 'ptadmin', ['v1'], ['admin-v1'], ['context'], new FrontendDevelopmentStore('production'));

        $resolved = $registry->resolve([['key' => 'app.users', 'type' => 'page', 'owner_type' => 'app', 'owner_code' => 'host']]);

        self::assertSame([], $resolved['modules']);
        self::assertSame('frontend_manifest_mismatch', $resolved['errors'][0]['error_code']);
    }

    public function testViteBuildMetadataIsVerifiedWithApplicationArtifacts(): void
    {
        mkdir($this->root.'/frontend/.vite', 0700, true);
        file_put_contents($this->root.'/frontend/app.css', 'body { color: black; }');
        file_put_contents($this->root.'/frontend/.vite/sparq-root.json', '{"schema":"sparq.frontend-build/v1","entry":"app.mjs","styles":["app.css"]}');
        (new FrontendPublisher($this->root.'/public'))->publishApplication('admintest', $this->root.'/frontend');
        $registry = new ApplicationFrontendRegistry($this->root, $this->root.'/public', 'admintest', 'ptadmin', ['v1'], ['admin-v1'], ['context'], new FrontendDevelopmentStore('production'));
        $menus = [['key' => 'app.users', 'type' => 'page', 'owner_type' => 'app', 'owner_code' => 'host']];

        $resolved = $registry->resolve($menus);

        self::assertSame([], $resolved['errors']);
        self::assertTrue($resolved['modules'][0]['entries'][0]['trust']['verified']);
        self::assertSame(['./modules/app/app.css?v='.hash_file('sha256', $this->root.'/frontend/app.css')], $resolved['modules'][0]['entries'][0]['styles']);

        file_put_contents($this->root.'/public/admintest/modules/app/.vite/sparq-root.json', '{"entry":"bad.mjs"}');
        $tampered = $registry->resolve($menus);

        self::assertSame([], $tampered['modules']);
        self::assertSame('frontend_manifest_mismatch', $tampered['errors'][0]['error_code']);
    }

    public function testBuildMetadataCannotBeUsedAsFrontendEntry(): void
    {
        mkdir($this->root.'/frontend/.vite', 0700, true);
        file_put_contents($this->root.'/frontend/.vite/sparq-root.json', '{}');
        $descriptorPath = $this->root.'/frontend/frontend.json';
        $descriptor = json_decode((string) file_get_contents($descriptorPath), true, 512, JSON_THROW_ON_ERROR);
        $descriptor['entries'][0]['entry'] = '.vite/sparq-root.json';
        file_put_contents($descriptorPath, json_encode($descriptor, JSON_THROW_ON_ERROR));
        (new FrontendPublisher($this->root.'/public'))->publishApplication('admintest', $this->root.'/frontend');
        $registry = new ApplicationFrontendRegistry($this->root, $this->root.'/public', 'admintest', 'ptadmin', ['v1'], ['admin-v1'], ['context'], new FrontendDevelopmentStore('production'));

        $resolved = $registry->resolve([['key' => 'app.users', 'type' => 'page', 'owner_type' => 'app', 'owner_code' => 'host']]);

        self::assertSame([], $resolved['modules']);
        self::assertSame('frontend_entry_invalid', $resolved['errors'][0]['error_code']);
    }

    public function testLocalAndTestingApplicationsUseSourceWithoutPublishedArtifacts(): void
    {
        $this->declareDevelopment();
        foreach (['local', 'testing'] as $environment) {
            $result = $this->registry($environment)->resolve($this->menus());
            self::assertSame([], $result['errors']);
            $entry = $result['modules'][0]['entries'][0];
            self::assertSame('http://127.0.0.1:51731/src/frontend.ts', $entry['development']['entry_url']);
            self::assertSame('http://127.0.0.1:51731', $entry['development']['allowed_origin']);
            self::assertSame('development', $entry['development']['load_mode']);
            self::assertTrue($entry['development']['hmr']);
            self::assertArrayNotHasKey('trust', $entry);
            self::assertArrayNotHasKey('styles', $entry);
        }
    }

    public function testProductionIgnoresSourceDeclarationAndStillRequiresVerifiedArtifacts(): void
    {
        $this->declareDevelopment(['entry_url' => 'javascript:invalid']);
        $registry = $this->registry('production');
        self::assertSame('frontend_manifest_unavailable', $registry->resolve($this->menus())['errors'][0]['error_code']);
        (new FrontendPublisher($this->root.'/public'))->publishApplication('admintest', $this->root.'/frontend');
        $result = $registry->resolve($this->menus());
        self::assertSame([], $result['errors']);
        $entry = $result['modules'][0]['entries'][0];
        self::assertArrayNotHasKey('development', $entry);
        self::assertTrue($entry['trust']['verified']);
    }

    public function testInvalidDevelopmentOriginsAndSandboxAreRejectedWithoutProductionFallback(): void
    {
        (new FrontendPublisher($this->root.'/public'))->publishApplication('admintest', $this->root.'/frontend');
        foreach ([
            ['entry_url' => 'https://untrusted.example/src/frontend.ts'],
            ['allowed_origin' => 'http://127.0.0.1:51732'],
            ['sandbox' => 'invalid'],
        ] as $invalid) {
            $this->declareDevelopment($invalid);
            $result = $this->registry('local')->resolve($this->menus());
            self::assertSame([], $result['modules']);
            self::assertSame('frontend_development_invalid', $result['errors'][0]['error_code']);
        }
        $this->declareDevelopment(['sandbox' => 'iframe']);
        self::assertSame('frontend_development_sandbox_mismatch', $this->registry('local')->resolve($this->menus())['errors'][0]['error_code']);
    }

    public function testNonlocalDevelopmentOriginUsesTheSharedHostAllowlist(): void
    {
        $this->declareDevelopment(['entry_url' => 'https://dev.example/src/frontend.ts']);
        $result = $this->registry('local', ['https://dev.example'])->resolve($this->menus());
        self::assertSame([], $result['errors']);
        self::assertSame('https://dev.example', $result['modules'][0]['entries'][0]['development']['allowed_origin']);
    }

    /** @param array<string,mixed> $overrides */
    private function declareDevelopment(array $overrides = []): void
    {
        $path = $this->root.'/frontend/frontend.json';
        $descriptor = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $descriptor['development'] = array_replace([
            'entry_url' => 'http://127.0.0.1:51731/src/frontend.ts',
            'sandbox' => 'trusted', 'dev_revision' => 'site-local', 'hmr' => true, 'source_maps' => true,
        ], $overrides);
        file_put_contents($path, json_encode($descriptor, JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $origins */
    private function registry(string $environment, array $origins = []): ApplicationFrontendRegistry
    {
        return new ApplicationFrontendRegistry(
            $this->root, $this->root.'/public', 'admintest', 'ptadmin', ['v1'], ['admin-v1'], ['context'],
            new FrontendDevelopmentStore($environment, $origins),
        );
    }

    /** @return list<array<string,string>> */
    private function menus(): array
    {
        return [['key' => 'app.users', 'type' => 'page', 'owner_type' => 'app', 'owner_code' => 'host']];
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory.'/'.$name;
            is_dir($path) ? $this->remove($path) : unlink($path);
        }
        rmdir($directory);
    }
}
