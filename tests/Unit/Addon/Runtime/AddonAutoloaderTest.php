<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon\Runtime;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Runtime\AddonAutoloader;

final class AddonAutoloaderTest extends TestCase
{
    private string $root;
    private string $namespace;
    private AddonAutoloader $loader;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-psr4-'.bin2hex(random_bytes(5));
        $this->namespace = 'AutoloadFixture'.bin2hex(random_bytes(5));
        mkdir($this->root.'/demo-addon/src/Services/SearchPush', 0700, true);
        $this->loader = new AddonAutoloader();
    }

    protected function tearDown(): void
    {
        unset($this->loader);
        $this->remove($this->root);
    }

    public function testStandardMappingWorksWithAnAuthoritativeHostAndAddsOnlyOneCallback(): void
    {
        $host = new ClassLoader();
        $host->setClassMapAuthoritative(true);
        $host->register(true);
        try {
            $class = $this->namespace.'\\Services\\SearchPush\\Probe';
            self::assertFalse(class_exists($class));
            $this->writeClass('Services/SearchPush/Probe', 'Services\\SearchPush', 'Probe');
            $callbacks = count(spl_autoload_functions());
            $this->loader->register($this->root.'/demo-addon', $this->manifest());
            $this->loader->register($this->root.'/demo-addon', $this->manifest());
            mkdir($this->root.'/another-addon/src', 0700, true);
            $this->loader->register($this->root.'/another-addon', [
                'code' => 'another-addon', 'namespace' => $this->namespace.'Other',
            ]);

            self::assertCount($callbacks + 1, spl_autoload_functions());
            self::assertTrue(class_exists($class));
            self::assertSame(realpath($this->root.'/demo-addon/src/Services/SearchPush/Probe.php'), (new \ReflectionClass($class))->getFileName());
            self::assertSame([], $host->getPrefixesPsr4());
            self::assertTrue($host->isClassMapAuthoritative());
        } finally {
            $host->unregister();
        }
    }

    public function testRefreshLetsAnUpdatedPackageResolveAPreviouslyMissingClass(): void
    {
        $this->loader->register($this->root.'/demo-addon', $this->manifest());
        $class = $this->namespace.'\\Services\\SearchPush\\Added';
        self::assertFalse(class_exists($class));
        $this->writeClass('Services/SearchPush/Added', 'Services\\SearchPush', 'Added');
        $this->loader->register($this->root.'/demo-addon', $this->manifest(), true);

        self::assertTrue(class_exists($class));
    }

    public function testReservingAnInactiveNamespaceDoesNotLoadItsClasses(): void
    {
        $this->writeClass('Services/SearchPush/Inactive', 'Services\\SearchPush', 'Inactive');
        $this->loader->reserve($this->manifest());

        self::assertFalse(class_exists($this->namespace.'\\Services\\SearchPush\\Inactive'));
        self::assertNull($this->loader->snapshot('demo-addon')['directory']);
    }

    /** @dataProvider conflictingNamespaces */
    public function testNamespaceConflictsAreRejected(string $namespace): void
    {
        $this->loader->reserve(['code' => 'first', 'namespace' => 'Vendor\\Plugin']);
        try {
            $this->loader->reserve(['code' => 'second', 'namespace' => $namespace]);
            self::fail('冲突的插件 namespace 必须被拒绝。');
        } catch (AddonException $exception) {
            self::assertSame('addon_namespace_conflict', $exception->errorCode);
            self::assertNull($this->loader->snapshot('second'));
        }
    }

    public static function conflictingNamespaces(): array
    {
        return [['Vendor\\Plugin'], ['vendor\\plugin'], ['Vendor'], ['Vendor\\Plugin\\Child']];
    }

    public function testNamespaceBoundaryAllowsSimilarNamesButRejectsChangingAnOwner(): void
    {
        $this->loader->reserve(['code' => 'first', 'namespace' => 'Vendor\\Plugin']);
        $this->loader->reserve(['code' => 'second', 'namespace' => 'Vendor\\PluginExtra']);
        self::assertSame('Vendor\\PluginExtra', $this->loader->snapshot('second')['namespace']);
        $this->expectException(AddonException::class);
        $this->loader->reserve(['code' => 'first', 'namespace' => 'Vendor\\Changed']);
    }

    public function testEscapingSourceSymlinkIsRejectedBeforeItReservesANamespace(): void
    {
        mkdir($this->root.'/outside', 0700, true);
        mkdir($this->root.'/unsafe-addon', 0700, true);
        symlink($this->root.'/outside', $this->root.'/unsafe-addon/src');
        try {
            $this->loader->register($this->root.'/unsafe-addon', $this->manifest());
            self::fail('src 不能指向插件目录之外。');
        } catch (AddonException $exception) {
            self::assertSame('unsafe_autoload_path', $exception->errorCode);
            self::assertNull($this->loader->snapshot('demo-addon'));
        }
    }

    public function testRestoringAMappingRemovesCandidateClassesAndClearsMissingClassCache(): void
    {
        $this->loader->register($this->root.'/demo-addon', $this->manifest());
        $previous = $this->loader->snapshot('demo-addon');
        self::assertFalse(class_exists($this->namespace.'\\Services\\SearchPush\\Restored'));
        mkdir($this->root.'/candidate/src', 0700, true);
        file_put_contents($this->root.'/candidate/src/Candidate.php', '<?php namespace '.$this->namespace.'; final class Candidate {}');
        $this->loader->register($this->root.'/candidate', $this->manifest());
        $this->loader->restore('demo-addon', $previous);
        $this->writeClass('Services/SearchPush/Restored', 'Services\\SearchPush', 'Restored');

        self::assertFalse(class_exists($this->namespace.'\\Candidate'));
        self::assertTrue(class_exists($this->namespace.'\\Services\\SearchPush\\Restored'));
        $this->loader->restore('demo-addon', null);
        self::assertNull($this->loader->snapshot('demo-addon'));
    }

    private function manifest(): array
    {
        return ['code' => 'demo-addon', 'namespace' => $this->namespace];
    }

    private function writeClass(string $path, string $namespace, string $class): void
    {
        file_put_contents($this->root.'/demo-addon/src/'.$path.'.php', '<?php namespace '.$this->namespace.'\\'.$namespace.'; final class '.$class.' {}');
    }

    private function remove(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if (!in_array($name, ['.', '..'], true)) {
                $this->remove($path.'/'.$name);
            }
        }
        rmdir($path);
    }
}
