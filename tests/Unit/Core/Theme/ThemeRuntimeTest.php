<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Theme;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Theme\ActiveThemeResolver;
use PTAdmin\Core\Theme\ThemeCatalog;
use PTAdmin\Core\Theme\ThemeAssetResolver;
use PTAdmin\Core\Theme\ThemeManifestValidator;
use PTAdmin\Core\Theme\ThemeStateStore;
use PTAdmin\Core\Theme\ThemeViewLoader;
use PTAdmin\Core\Theme\ThemeDirectiveProvider;
use PTAdmin\Core\Theme\ThemeException;
use PTAdmin\Core\Theme\ThemeInstaller;
use PTAdmin\Core\Theme\ThemeLifecycle;
use PTAdmin\Core\Theme\ThemePackageExtractor;
use PTAdmin\Core\Theme\ThemePublisher;
use PTAdmin\Template\Directive\DirectiveRegistry;
use PTAdmin\Template\Runtime\TemplateRenderer;

final class ThemeRuntimeTest extends TestCase
{
    private string $root;
    private string $stateFile;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-theme-'.bin2hex(random_bytes(5));
        $this->stateFile = $this->root.'/storage/theme.json';
        mkdir($this->root.'/storage', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testExplicitThemeIsTheOnlyResolvedTheme(): void
    {
        $this->theme('classic@1.0.0', ['code' => 'classic', 'version' => '1.0.0', 'name' => 'Classic']);
        $this->theme('other@1.0.0', ['code' => 'other', 'version' => '1.0.0', 'name' => 'Other']);
        file_put_contents($this->stateFile, json_encode(['code' => 'classic', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));

        $resolver = $this->resolver();
        $theme = $resolver->resolve();

        self::assertNotNull($theme);
        self::assertSame('classic@1.0.0', $theme->identifier());
        self::assertFalse($theme->isDefault);
    }

    public function testMissingStateUsesConfiguredDefaultVersionWithoutWritingState(): void
    {
        $this->theme('default@1.0.0', ['code' => 'default', 'version' => '1.0.0', 'name' => 'Default']);

        $resolver = $this->resolver();
        $theme = $resolver->resolve();

        self::assertNotNull($theme);
        self::assertSame('default@1.0.0', $theme->identifier());
        self::assertTrue($theme->isDefault);
        self::assertFileDoesNotExist($this->stateFile);
    }

    public function testMissingDefaultLeavesCoreInAValidNoThemeState(): void
    {
        $resolver = $this->resolver();

        self::assertNull($resolver->resolve());
        self::assertSame('default_theme_unavailable', $resolver->diagnostic()['code']);
    }

    public function testViewLoaderRegistersOnlyTheSelectedThemeAndClearsNoThemeState(): void
    {
        $applicationViews = $this->root.'/resources/views';
        mkdir($applicationViews, 0700, true);
        $this->theme('classic@1.0.0', ['code' => 'classic', 'version' => '1.0.0', 'name' => 'Classic']);
        $theme = (new ThemeCatalog($this->root, new ThemeManifestValidator()))->find('classic', '1.0.0');
        self::assertNotNull($theme);
        $finder = new FileViewFinder(new Filesystem(), [$applicationViews]);
        $loader = new ThemeViewLoader($finder);

        $loader->load($theme);

        self::assertSame([$theme->directory], $finder->getPaths());

        $loader->load(null);

        self::assertSame([], $finder->getPaths());
    }

    public function testInvalidExplicitThemeDoesNotFallbackToDefault(): void
    {
        $this->theme('default@1.0.0', ['code' => 'default', 'version' => '1.0.0', 'name' => 'Default']);
        file_put_contents($this->stateFile, json_encode(['code' => 'missing', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));

        $resolver = $this->resolver();

        self::assertNull($resolver->resolve());
        self::assertSame('theme_unavailable', $resolver->diagnostic()['code']);
    }

    public function testCatalogReportsManifestIdentityMismatch(): void
    {
        $this->theme('classic@1.0.0', ['code' => 'other', 'version' => '1.0.0', 'name' => 'Other']);

        $result = (new ThemeCatalog($this->root, new ThemeManifestValidator()))->scan();

        self::assertCount(0, $result['themes']);
        self::assertSame('theme_identity_mismatch', $result['errors'][0]['error_code']);
    }

    public function testThemeAssetUrlIncludesTheActiveThemeVersion(): void
    {
        $this->theme('classic@1.0.0', ['code' => 'classic', 'version' => '1.0.0', 'name' => 'Classic']);
        mkdir($this->root.'/classic@1.0.0/assets/css', 0700, true);
        file_put_contents($this->root.'/classic@1.0.0/assets/css/site.css', 'body {}');
        file_put_contents($this->stateFile, json_encode(['code' => 'classic', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));

        $resolver = new ThemeAssetResolver($this->resolver());

        self::assertSame('/themes/classic@1.0.0/assets/css/site.css', $resolver->url('css/site.css'));
    }

    public function testThemeAssetUrlUsesDevelopmentPrefixOnlyForConfiguredActiveTheme(): void
    {
        $this->theme('classic@1.0.0', ['code' => 'classic', 'version' => '1.0.0', 'name' => 'Classic']);
        mkdir($this->root.'/classic@1.0.0/assets/css', 0700, true);
        file_put_contents($this->root.'/classic@1.0.0/assets/css/site.css', 'body {}');
        file_put_contents($this->stateFile, json_encode(['code' => 'classic', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));

        $resolver = new ThemeAssetResolver($this->resolver(), [
            'enabled' => true, 'code' => 'classic', 'version' => '1.0.0', 'prefix' => '__dev_theme__',
        ]);

        self::assertSame('/__dev_theme__/classic@1.0.0/assets/css/site.css', $resolver->url('css/site.css'));
        self::assertSame('/themes/classic@1.0.0/assets/css/site.css', (new ThemeAssetResolver($this->resolver(), [
            'enabled' => true, 'code' => 'other', 'version' => '1.0.0',
        ]))->url('css/site.css'));
    }

    public function testThemeAssetPathCannotEscapeTheActiveTheme(): void
    {
        $this->theme('classic@1.0.0', ['code' => 'classic', 'version' => '1.0.0', 'name' => 'Classic']);
        mkdir($this->root.'/classic@1.0.0/assets', 0700, true);
        file_put_contents($this->stateFile, json_encode(['code' => 'classic', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));
        $resolver = new ThemeAssetResolver($this->resolver());

        $this->expectException(\InvalidArgumentException::class);
        $resolver->url('../theme.json');
    }

    public function testStateStoreAtomicallyWritesThemePointer(): void
    {
        $this->theme('classic@1.0.0', ['code' => 'classic', 'version' => '1.0.0', 'name' => 'Classic']);
        $theme = (new ThemeCatalog($this->root, new ThemeManifestValidator()))->find('classic', '1.0.0');
        self::assertNotNull($theme);

        (new ThemeStateStore($this->stateFile))->write($theme, '2026-09-22T12:00:00+08:00');

        self::assertJsonStringEqualsJsonString(
            json_encode(['code' => 'classic', 'version' => '1.0.0', 'activated_at' => '2026-09-22T12:00:00+08:00'], JSON_THROW_ON_ERROR),
            (string) file_get_contents($this->stateFile),
        );
        self::assertSame([], glob($this->root.'/storage/.theme-state-*') ?: []);
    }

    public function testThemeAssetDirectiveUsesTheActiveThemeResolver(): void
    {
        $this->theme('classic@1.0.0', ['code' => 'classic', 'version' => '1.0.0', 'name' => 'Classic']);
        mkdir($this->root.'/classic@1.0.0/assets/css', 0700, true);
        file_put_contents($this->root.'/classic@1.0.0/assets/css/site.css', 'body {}');
        file_put_contents($this->stateFile, json_encode(['code' => 'classic', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));

        $registry = new DirectiveRegistry();
        $registration = $registry->forNamespace('core');
        (new ThemeDirectiveProvider(new ThemeAssetResolver($this->resolver())))->register($registration);
        $registration->commit();

        self::assertSame('/themes/classic@1.0.0/assets/css/site.css', (new TemplateRenderer($registry))->render('@pt:theme_asset(path="css/site.css")'));
    }

    public function testThemeInstallerMovesSourceWithoutPublishingPublicAssets(): void
    {
        $source = $this->root.'/source/classic@1.0.0';
        mkdir($source.'/assets/css', 0700, true);
        file_put_contents($source.'/theme.json', json_encode(['code' => 'classic', 'version' => '1.0.0', 'name' => 'Classic'], JSON_THROW_ON_ERROR));
        file_put_contents($source.'/cover.webp', 'cover');
        file_put_contents($source.'/assets/css/site.css', 'body {}');
        file_put_contents($source.'/internal.txt', 'private');

        $result = $this->installer()->install($this->root.'/source/classic@1.0.0');

        self::assertSame('classic@1.0.0', $result['code'].'@'.$result['version']);
        self::assertFileExists($this->root.'/templates/classic@1.0.0/theme.json');
        self::assertFalse($result['public']);
        self::assertDirectoryDoesNotExist($this->root.'/public/themes/classic@1.0.0');
    }

    public function testThemeInstallerRejectsExistingVersionWithoutOverwritingIt(): void
    {
        $source = $this->root.'/source/classic@1.0.0';
        mkdir($source, 0700, true);
        file_put_contents($source.'/theme.json', json_encode(['code' => 'classic', 'version' => '1.0.0', 'name' => 'New'], JSON_THROW_ON_ERROR));
        mkdir($this->root.'/templates/classic@1.0.0', 0700, true);
        file_put_contents($this->root.'/templates/classic@1.0.0/theme.json', json_encode(['code' => 'classic', 'version' => '1.0.0', 'name' => 'Existing'], JSON_THROW_ON_ERROR));

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('already installed');
        $this->installer()->install($source);
    }

    public function testThemeInstallerCleansNewSourceWhenValidationFails(): void
    {
        $source = $this->root.'/source/classic@1.0.0';
        mkdir($source.'/assets', 0700, true);
        file_put_contents($source.'/theme.json', json_encode(['code' => 'classic', 'version' => '1.0.0', 'name' => 'Classic'], JSON_THROW_ON_ERROR));
        file_put_contents($source.'/assets/template.php', '<?php');

        try {
            $this->installer()->install($source);
            self::fail('Executable public assets must be rejected.');
        } catch (ThemeException $exception) {
            self::assertSame('unsafe_theme_path', $exception->errorCode);
        }
        self::assertDirectoryDoesNotExist($this->root.'/templates/classic@1.0.0');
    }

    public function testActivatingThemePublishesItsPublicAssets(): void
    {
        $this->theme('classic@1.0.0', ['code' => 'classic', 'version' => '1.0.0', 'name' => 'Classic']);

        $result = $this->lifecycle()->activate('classic', '1.0.0');

        self::assertTrue($result['active']);
        self::assertDirectoryExists($this->root.'/public/themes/classic@1.0.0');
    }

    public function testActivationWritesTheSelectedThemePointer(): void
    {
        $this->theme('classic@1.0.0', ['code' => 'classic', 'version' => '1.0.0', 'name' => 'Classic']);

        $result = $this->lifecycle()->activate('classic', '1.0.0');

        self::assertTrue($result['active']);
        self::assertSame('classic', json_decode((string) file_get_contents($this->stateFile), true, 512, JSON_THROW_ON_ERROR)['code']);
    }

    public function testActivationInvalidatesCachedNoThemeResolution(): void
    {
        $this->theme('classic@1.0.0', ['code' => 'classic', 'version' => '1.0.0', 'name' => 'Classic']);
        $catalog = new ThemeCatalog($this->root, new ThemeManifestValidator());
        $active = new ActiveThemeResolver($catalog, $this->stateFile, 'default', '1.0.0');
        self::assertNull($active->resolve());

        $descriptor = $catalog->find('classic', '1.0.0');
        self::assertNotNull($descriptor);
        $publisher = new ThemePublisher($this->root.'/public/themes');
        $lifecycle = new ThemeLifecycle(
            $catalog,
            $active,
            new ThemeStateStore($this->stateFile),
            $publisher,
            $this->root,
        );

        $lifecycle->activate('classic', '1.0.0');

        self::assertSame('classic@1.0.0', $active->active()?->identifier());
    }

    public function testActivationRemovesThePreviouslyActivePublicAssets(): void
    {
        $this->theme('classic@1.0.0', ['code' => 'classic', 'version' => '1.0.0', 'name' => 'Classic']);
        $this->theme('studio@1.0.0', ['code' => 'studio', 'version' => '1.0.0', 'name' => 'Studio']);

        $lifecycle = $this->lifecycle();
        $lifecycle->activate('classic', '1.0.0');
        self::assertDirectoryExists($this->root.'/public/themes/classic@1.0.0');

        $lifecycle->activate('studio', '1.0.0');

        self::assertDirectoryExists($this->root.'/public/themes/studio@1.0.0');
        self::assertDirectoryDoesNotExist($this->root.'/public/themes/classic@1.0.0');
    }

    public function testCurrentAndDefaultThemesCannotBeRemoved(): void
    {
        $this->theme('default@1.0.0', ['code' => 'default', 'version' => '1.0.0', 'name' => 'Default']);
        $this->theme('classic@1.0.0', ['code' => 'classic', 'version' => '1.0.0', 'name' => 'Classic']);
        file_put_contents($this->stateFile, json_encode(['code' => 'classic', 'version' => '1.0.0'], JSON_THROW_ON_ERROR));

        $lifecycle = $this->lifecycle();
        try {
            $lifecycle->remove('classic', '1.0.0');
            self::fail('The active theme must be protected.');
        } catch (ThemeException $exception) {
            self::assertSame('theme_active_protected', $exception->errorCode);
        }
        try {
            $lifecycle->remove('default', '1.0.0');
            self::fail('The default theme must be protected.');
        } catch (ThemeException $exception) {
            self::assertSame('theme_default_protected', $exception->errorCode);
        }
    }

    public function testInactiveThemeRemovalDeletesSourceAndPublicAssets(): void
    {
        $this->theme('classic@1.0.0', ['code' => 'classic', 'version' => '1.0.0', 'name' => 'Classic']);

        $result = $this->lifecycle()->remove('classic', '1.0.0');

        self::assertTrue($result['removed']);
        self::assertDirectoryDoesNotExist($this->root.'/classic@1.0.0');
        self::assertDirectoryDoesNotExist($this->root.'/public/themes/classic@1.0.0');
    }

    private function installer(): ThemeInstaller
    {
        return new ThemeInstaller(
            new ThemePackageExtractor(),
            new ThemeManifestValidator(),
            $this->root.'/templates',
            $this->root.'/storage/staging',
        );
    }

    private function lifecycle(): ThemeLifecycle
    {
        $catalog = new ThemeCatalog($this->root, new ThemeManifestValidator());
        $state = new ThemeStateStore($this->stateFile);
        $active = new ActiveThemeResolver($catalog, $this->stateFile, 'default', '1.0.0');
        $publisher = new ThemePublisher($this->root.'/public/themes');

        return new ThemeLifecycle($catalog, $active, $state, $publisher, $this->root);
    }

    /** @param array<string,mixed> $manifest */
    private function theme(string $directory, array $manifest): void
    {
        $path = $this->root.'/'.$directory;
        mkdir($path, 0700, true);
        file_put_contents($path.'/theme.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    private function resolver(): ActiveThemeResolver
    {
        return new ActiveThemeResolver(
            new ThemeCatalog($this->root, new ThemeManifestValidator()),
            $this->stateFile,
            'default',
            '1.0.0',
        );
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
            is_dir($entry) && !is_link($entry) ? $this->remove($entry) : @unlink($entry);
        }
        @rmdir($path);
    }
}
