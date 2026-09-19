<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use PTAdmin\Schema\Runtime\EventMode;
use PTAdmin\Schema\Runtime\RuntimeCacheStore;
use PTAdmin\Schema\Runtime\RuntimeDiagnostic;
use PTAdmin\Schema\Runtime\RuntimeRegistry;

final class RuntimeRegistryTest extends TestCase
{
    public function testBuildCreatesStableHookActionAndEventDescriptors(): void
    {
        $registry = new RuntimeRegistry();
        $registry->forOwner('core', 'ptadmin')
            ->resource('admin.management_users')
            ->hook('create.prepare', RuntimeHandler::class, 'prepare', 100)
            ->action('sync_roles', RuntimeHandler::class, 'action')
            ->event('created', RuntimeHandler::class, 'event', EventMode::Queued);
        $registry->forOwner('app', 'host')
            ->resource('admin.management_users')
            ->hook('output.transform', RuntimeHandler::class, 'output');

        $snapshot = $registry->build();
        $resource = $snapshot->toArray()['resources']['admin.management_users'];

        self::assertSame('ptadmin', $resource['hooks']['create.prepare'][0]['owner']['code']);
        self::assertSame('host', $resource['hooks']['output.transform'][0]['owner']['code']);
        self::assertSame('sync_roles', $resource['actions']['sync_roles']['action_key'] ?? 'sync_roles');
        self::assertSame('queued', $resource['events']['created'][0]['mode']);
        self::assertSame($snapshot->checksum(), $snapshot->toArray()['checksum']);
    }

    public function testHooksAndEventsAreSortedByPriorityThenRegistrationSequence(): void
    {
        $registry = new RuntimeRegistry();
        $resource = $registry->forOwner('core', 'ptadmin')->resource('cms.articles');
        $resource->hook('create.before', RuntimeHandler::class, 'before', 10);
        $resource->hook('create.before', RuntimeHandler::class, 'beforeSamePriority', 10);
        $resource->hook('create.before', RuntimeHandler::class, 'beforeLow', 0);
        $resource->hook('create.before', RuntimeHandler::class, 'beforeHigh', 100);
        $resource->event('created', RuntimeHandler::class, 'event', EventMode::SyncAfterCommit, 0);
        $resource->event('created', RuntimeHandler::class, 'eventHigh', EventMode::SyncAfterCommit, 50);

        $descriptor = $registry->build()->toArray()['resources']['cms.articles'];

        self::assertSame(['beforeHigh', 'before', 'beforeSamePriority', 'beforeLow'], array_column($descriptor['hooks']['create.before'], 'method'));
        self::assertSame(['eventHigh', 'event'], array_column($descriptor['events']['created'], 'method'));
    }

    public function testRegistryRejectsInvalidOwnerAndHookStage(): void
    {
        $this->expectException(RuntimeDiagnostic::class);
        $this->expectExceptionMessage('Application Runtime Owner');

        (new RuntimeRegistry())->forOwner('app', 'frontend');
    }

    public function testRegistryRejectsInvalidHookStage(): void
    {
        $registry = new RuntimeRegistry();
        $registry->forOwner('core', 'ptadmin')->resource('cms.articles')
            ->hook('create.invalid', RuntimeHandler::class, 'before');

        try {
            $registry->build();
            self::fail('Expected invalid Hook stage diagnostic.');
        } catch (RuntimeDiagnostic $exception) {
            self::assertSame('hook_stage_invalid', $exception->diagnosticCode);
        }
    }

    public function testRegistryRejectsUnavailableAndNonPublicServiceMethods(): void
    {
        $missing = new RuntimeRegistry();
        $missing->forOwner('core', 'ptadmin')->resource('cms.articles')
            ->hook('create.before', RuntimeHandler::class, 'missing');

        try {
            $missing->build();
            self::fail('Expected invalid method diagnostic.');
        } catch (RuntimeDiagnostic $exception) {
            self::assertSame('runtime_method_invalid', $exception->diagnosticCode);
        }

        $private = new RuntimeRegistry();
        $private->forOwner('core', 'ptadmin')->resource('cms.articles')
            ->hook('create.before', RuntimeHandler::class, 'hidden');

        try {
            $private->build();
            self::fail('Expected non-public method diagnostic.');
        } catch (RuntimeDiagnostic $exception) {
            self::assertSame('runtime_method_invalid', $exception->diagnosticCode);
        }
    }

    public function testRegistryRejectsReservedActions(): void
    {
        $reserved = new RuntimeRegistry();
        $reserved->forOwner('core', 'ptadmin')->resource('cms.articles')->action('create', RuntimeHandler::class, 'action');
        $this->expectException(RuntimeDiagnostic::class);
        $this->expectExceptionMessage('CRUD Action');
        $reserved->build();
    }

    public function testRegistryRejectsDuplicateActionBindings(): void
    {
        $registry = new RuntimeRegistry();
        $resource = $registry->forOwner('addon', 'cms')->resource('cms.articles');
        $resource->action('publish', RuntimeHandler::class, 'action');
        $resource->action('publish', RuntimeHandler::class, 'other');

        try {
            $registry->build();
            self::fail('Expected duplicate Action diagnostic.');
        } catch (RuntimeDiagnostic $exception) {
            self::assertSame('action_binding_duplicate', $exception->diagnosticCode);
        }
    }

    public function testRegistryFreezesAfterSuccessfulBuild(): void
    {
        $registry = new RuntimeRegistry();
        $registry->forOwner('core', 'ptadmin')->resource('cms.articles');
        $registry->build();

        $this->expectException(RuntimeDiagnostic::class);
        $this->expectExceptionMessage('already been built');
        $registry->forOwner('core', 'ptadmin');
    }

    public function testCacheStoreWritesAtomicallyAndFallsBackForInvalidCache(): void
    {
        $path = sys_get_temp_dir().'/ptadmin-runtime-'.bin2hex(random_bytes(5)).'/ptadmin-runtime.php';
        $store = new RuntimeCacheStore($path);
        $registry = new RuntimeRegistry();
        $registry->forOwner('core', 'ptadmin')->resource('cms.articles')->hook('create.before', RuntimeHandler::class, 'before');
        $snapshot = $registry->build();

        $store->write($snapshot);
        self::assertSame($snapshot->toArray(), $store->read()?->toArray());

        file_put_contents($path, "<?php return ['format' => 999];");
        self::assertNull($store->read());

        $store->write($snapshot);
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        file_put_contents($path, str_replace("'checksum' => '", "'checksum' => 'broken", $contents));
        self::assertNull($store->read());

        unlink($path);
        rmdir(dirname($path));
    }
}

final class RuntimeHandler
{
    public function prepare(): array { return []; }
    public function before(): void {}
    public function beforeSamePriority(): void {}
    public function beforeLow(): void {}
    public function beforeHigh(): void {}
    public function output(): array { return []; }
    public function action(): array { return []; }
    public function other(): array { return []; }
    public function event(): void {}
    public function eventHigh(): void {}
    private function hidden(): void {}
}
