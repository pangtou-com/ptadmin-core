<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Theme;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Theme\{ActiveThemeResolver, ThemeCatalog, ThemeException, ThemeLifecycle, ThemeManifestValidator, ThemePublisher, ThemeStateStore};

final class ThemeResourcePublicationTest extends TestCase
{
    private string $root;
    private string $source;
    private string $target;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/theme-publication-'.bin2hex(random_bytes(6));
        $this->source = $this->root.'/templates/default@1.0.0';
        $this->target = $this->root.'/public/themes/default@1.0.0';
        mkdir($this->source.'/assets/css', 0700, true);
        file_put_contents($this->source.'/theme.json', json_encode(['code' => 'default', 'version' => '1.0.0', 'name' => '默认主题']));
        file_put_contents($this->source.'/index.blade.php', 'private template');
        file_put_contents($this->source.'/cover.png', 'cover');
        file_put_contents($this->source.'/assets/css/site.css', 'old css');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->root);
    }

    public function testFirstAndRepeatedPublicationReplaceOnlyPublicAssetsWithPrivateBackup(): void
    {
        $lifecycle = $this->lifecycle();
        $first = $lifecycle->publishAssets('default', '1.0.0');
        self::assertSame(2, $first['files']);
        self::assertNull($first['backup_id']);
        self::assertFileDoesNotExist($this->root.'/theme.json');
        self::assertFileDoesNotExist($this->target.'/theme.json');
        self::assertFileDoesNotExist($this->target.'/index.blade.php');
        $lifecycle->activate('default', '1.0.0');
        $state = file_get_contents($this->root.'/theme.json');
        file_put_contents($this->source.'/assets/css/site.css', 'new css');
        file_put_contents($this->target.'/assets/old.js', 'old js');
        $result = $lifecycle->publishAssets('default', '1.0.0');
        self::assertSame('new css', file_get_contents($this->target.'/assets/css/site.css'));
        self::assertFileDoesNotExist($this->target.'/assets/old.js');
        self::assertSame('old css', file_get_contents($this->root.'/operations/backups/'.$result['backup_id'].'/assets/css/site.css'));
        self::assertSame('old js', file_get_contents($this->root.'/operations/backups/'.$result['backup_id'].'/assets/old.js'));
        self::assertSame($state, file_get_contents($this->root.'/theme.json'));
        self::assertSame('private template', file_get_contents($this->source.'/index.blade.php'));
        $again = $lifecycle->publishAssets('default', '1.0.0');
        self::assertNotSame($result['backup_id'], $again['backup_id']);
        self::assertSame('new css', file_get_contents($this->target.'/assets/css/site.css'));
        self::assertSame([], glob($this->root.'/public/themes/.publish-*'));
        self::assertSame([], glob($this->root.'/public/themes/.previous-*'));
    }

    public function testInvalidSourceLeavesExistingAssetsUntouched(): void
    {
        $lifecycle = $this->lifecycle();
        $lifecycle->publishAssets('default', '1.0.0');
        file_put_contents($this->source.'/assets/run.php', '<?php');
        try {
            $lifecycle->publishAssets('default', '1.0.0');
            self::fail('Executable assets must fail.');
        } catch (ThemeException $error) {
            self::assertSame('unsafe_theme_path', $error->errorCode);
        }
        self::assertSame('old css', file_get_contents($this->target.'/assets/css/site.css'));
        self::assertFileDoesNotExist($this->target.'/assets/run.php');
        self::assertSame([], glob($this->root.'/public/themes/.publish-*'));
    }

    public function testSymlinkTargetCannotOverwriteAnotherDirectory(): void
    {
        mkdir($this->root.'/public/themes', 0700, true);
        mkdir($this->root.'/outside', 0700);
        file_put_contents($this->root.'/outside/keep', 'unchanged');
        symlink($this->root.'/outside', $this->target);
        try {
            $this->lifecycle()->publishAssets('default', '1.0.0');
            self::fail('Symlink targets must fail.');
        } catch (ThemeException $error) {
            self::assertSame('unsafe_theme_path', $error->errorCode);
        }
        self::assertSame('unchanged', file_get_contents($this->root.'/outside/keep'));
        self::assertTrue(is_link($this->target));
    }

    public function testSourceSymlinksAndInvalidManifestCannotReplacePublishedAssets(): void
    {
        $lifecycle = $this->lifecycle();
        $lifecycle->publishAssets('default', '1.0.0');
        symlink($this->source.'/theme.json', $this->source.'/assets/linked.json');
        try {
            $lifecycle->publishAssets('default', '1.0.0');
            self::fail('Symlink sources must fail.');
        } catch (ThemeException $error) {
            self::assertSame('theme_not_found', $error->errorCode);
        }
        unlink($this->source.'/assets/linked.json');
        file_put_contents($this->source.'/theme.json', '{}');
        try {
            $lifecycle->publishAssets('default', '1.0.0');
            self::fail('Invalid manifests must fail.');
        } catch (ThemeException $error) {
            self::assertSame('theme_not_found', $error->errorCode);
        }
        self::assertSame('old css', file_get_contents($this->target.'/assets/css/site.css'));
    }

    public function testLinkedThemeRootCannotPublishFilesOutsideTemplates(): void
    {
        rename($this->source, $this->root.'/outside-theme');
        symlink($this->root.'/outside-theme', $this->source);
        try {
            $this->lifecycle()->publishAssets('default', '1.0.0');
            self::fail('Linked theme root must fail.');
        } catch (ThemeException $error) {
            self::assertSame('theme_not_found', $error->errorCode);
        }
        self::assertDirectoryDoesNotExist($this->target);
        self::assertSame('old css', file_get_contents($this->root.'/outside-theme/assets/css/site.css'));
    }

    public function testStaleSelectionCannotPublishAnInactiveTheme(): void
    {
        $lifecycle = $this->lifecycle();
        $lifecycle->publishAssets('default', '1.0.0');
        file_put_contents($this->root.'/theme.json', json_encode(['code' => 'missing', 'version' => '1.0.0']));
        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('只能发布当前启用主题');
        $lifecycle->publishAssets('default', '1.0.0');
    }

    public function testPublicationAndActivationShareAnExclusiveLock(): void
    {
        mkdir($this->root.'/operations', 0700);
        $lock = fopen($this->root.'/operations/lifecycle.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            foreach (['publishAssets', 'activate', 'remove'] as $operation) {
                try {
                    $this->lifecycle()->$operation('default', '1.0.0');
                    self::fail('Concurrent mutation must fail.');
                } catch (ThemeException $error) {
                    self::assertSame('theme_busy', $error->errorCode);
                }
            }
            self::assertDirectoryDoesNotExist($this->target);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testFailedBackupLeavesPublishedDirectoryUntouched(): void
    {
        $lifecycle = $this->lifecycle();
        $lifecycle->publishAssets('default', '1.0.0');
        file_put_contents($this->target.'/assets/private.php', 'keep private file');
        file_put_contents($this->source.'/assets/css/site.css', 'new css');
        try {
            $lifecycle->publishAssets('default', '1.0.0');
            self::fail('Unsafe existing directory must fail before swap.');
        } catch (ThemeException $error) {
            self::assertSame('unsafe_theme_path', $error->errorCode);
        }
        self::assertSame('old css', file_get_contents($this->target.'/assets/css/site.css'));
        self::assertSame('keep private file', file_get_contents($this->target.'/assets/private.php'));
    }

    public function testSwapFailureRestoresOriginalDirectory(): void
    {
        $this->lifecycle()->publishAssets('default', '1.0.0');
        file_put_contents($this->source.'/assets/css/site.css', 'new css');
        $files = $this->getMockBuilder(Filesystem::class)->onlyMethods(['move'])->getMock();
        $files->method('move')->willReturnCallback(static function (string $source, string $target): bool {
            if (str_contains($source, '/.publish-')) {
                throw new \RuntimeException('simulated rename failure');
            }
            return rename($source, $target);
        });
        try {
            $this->lifecycle($files)->publishAssets('default', '1.0.0');
            self::fail('Swap should fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('simulated rename failure', $error->getMessage());
        }
        self::assertSame('old css', file_get_contents($this->target.'/assets/css/site.css'));
        self::assertCount(1, glob($this->root.'/operations/backups/*'));
        self::assertSame([], glob($this->root.'/public/themes/.previous-*'));
    }

    private function lifecycle(?Filesystem $files = null): ThemeLifecycle
    {
        $catalog = new ThemeCatalog($this->root.'/templates', new ThemeManifestValidator());
        return new ThemeLifecycle($catalog, new ActiveThemeResolver($catalog, $this->root.'/theme.json'),
            new ThemeStateStore($this->root.'/theme.json'), new ThemePublisher($this->root.'/public/themes', $files ?? new Filesystem()),
            $this->root.'/templates', operationsDirectory: $this->root.'/operations');
    }
}
