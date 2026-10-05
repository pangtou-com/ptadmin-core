<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Theme;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use PTAdmin\Addon\Exception\AddonException;
use PTAdmin\Addon\Manifest\ManifestVerifier;
use PTAdmin\Core\Theme\ThemeException;
use PTAdmin\Core\Theme\ThemeManifestValidator;

final class MarketplaceValidationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-market-validation-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->root);
    }

    public function testFutureAddonCanBeInspectedWhileRuntimeStillEnforcesCurrentHost(): void
    {
        file_put_contents($this->root.'/manifest.json', json_encode([
            'code' => 'future-addon', 'version' => '1.0.0', 'compatibility' => ['ptadmin/core' => '>=9.0.0'],
        ], JSON_THROW_ON_ERROR));
        $verifier = new ManifestVerifier();
        self::assertSame('future-addon', $verifier->verify($this->root, false, false)['code']);
        foreach (['verify', 'verifyRuntime'] as $method) {
            try {
                $verifier->{$method}($this->root);
                self::fail('Installation and runtime must reject incompatible packages.');
            } catch (AddonException $exception) {
                self::assertSame('backend_incompatible', $exception->errorCode);
            }
        }
        try {
            $verifier->verify($this->root, true, false);
            self::fail('Market inspection must not disable signature requirements.');
        } catch (AddonException $exception) {
            self::assertSame('signature_missing', $exception->errorCode);
        }
    }

    public function testMarketAddonInspectionStillRejectsInvalidCompatibilitySyntax(): void
    {
        file_put_contents($this->root.'/manifest.json', json_encode([
            'code' => 'future-addon', 'version' => '1.0.0', 'compatibility' => ['ptadmin/core' => 'broken-constraint'],
        ], JSON_THROW_ON_ERROR));
        try {
            (new ManifestVerifier())->verify($this->root, false, false);
            self::fail('Market inspection must parse constraints.');
        } catch (AddonException $exception) {
            self::assertSame('invalid_constraint', $exception->errorCode);
        }
    }

    public function testFutureThemeCanBeInspectedWithoutChangingInstallationChecks(): void
    {
        file_put_contents($this->root.'/theme.json', json_encode([
            'code' => 'future-theme', 'version' => '1.0.0', 'name' => 'Future', 'compatibility' => '>=9.0.0',
        ], JSON_THROW_ON_ERROR));
        $validator = new ThemeManifestValidator();
        self::assertSame('future-theme', $validator->validateDirectory($this->root, 'future-theme', '1.0.0', false, false)->code);
        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('incompatible');
        $validator->validateDirectory($this->root, 'future-theme', '1.0.0');
    }

    public function testMarketThemeInspectionStillRejectsInvalidCompatibilitySyntax(): void
    {
        file_put_contents($this->root.'/theme.json', json_encode([
            'code' => 'future-theme', 'version' => '1.0.0', 'name' => 'Future', 'compatibility' => 'broken-constraint',
        ], JSON_THROW_ON_ERROR));
        try {
            (new ThemeManifestValidator())->validateDirectory($this->root, 'future-theme', '1.0.0', false, false);
            self::fail('Market inspection must parse constraints.');
        } catch (ThemeException $exception) {
            self::assertSame('invalid_theme_constraint', $exception->errorCode);
        }
    }
}
