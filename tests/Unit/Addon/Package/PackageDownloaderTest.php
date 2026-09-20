<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Addon\Package;

use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Package\PackageDownloader;

final class PackageDownloaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-addon-download-'.bin2hex(random_bytes(5));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->root);
    }

    public function testLocalArtifactIsCopiedAndVerified(): void
    {
        $source = $this->root.'/source.zip';
        file_put_contents($source, 'package');
        $result = (new PackageDownloader())->download($source, $this->root.'/artifact.zip', hash_file('sha256', $source));
        self::assertSame(hash_file('sha256', $source), $result['sha256']);
        self::assertSame('package', file_get_contents($result['artifact']));
    }

    public function testChecksumMismatchDoesNotPublishArtifact(): void
    {
        $source = $this->root.'/source.zip';
        file_put_contents($source, 'package');
        try {
            (new PackageDownloader())->download($source, $this->root.'/artifact.zip', str_repeat('0', 64));
            self::fail('Checksum mismatch must be rejected.');
        } catch (AddonException $exception) {
            self::assertSame('checksum_mismatch', $exception->errorCode);
            self::assertFileDoesNotExist($this->root.'/artifact.zip');
        }
    }
}
