<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PTAdmin\Core\ApplicationIdentity;
use PTAdmin\Core\Exceptions\ApplicationIdentityUnavailableException;

final class ApplicationIdentityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $directory = sys_get_temp_dir().'/ptadmin-core-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory.'/*') ?: [];
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testInstallCreationIsStableAndPrivate(): void
    {
        $identity = new ApplicationIdentity($this->directory.'/ptadmin.json');
        $created = $identity->createForInstall();
        $loaded = $identity->load();

        self::assertSame($created, $loaded);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $created['application_instance_id']);
        self::assertSame(0600, fileperms($identity->path()) & 0777);
    }

    public function testMissingIdentityIsNotRebuiltByLoad(): void
    {
        $identity = new ApplicationIdentity($this->directory.'/ptadmin.json');

        $this->expectException(ApplicationIdentityUnavailableException::class);
        $identity->load();
    }

    public function testRebuildCreatesANewInstance(): void
    {
        $identity = new ApplicationIdentity($this->directory.'/ptadmin.json');
        $first = $identity->createForInstall();
        $second = $identity->rebuild();

        self::assertNotSame($first['application_instance_id'], $second['application_instance_id']);
        self::assertSame($second, $identity->load());
    }
}
