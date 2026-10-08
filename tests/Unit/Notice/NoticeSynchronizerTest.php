<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Notice;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use PTAdmin\Notice\NoticeRegistry;
use PTAdmin\Notice\NoticeSynchronizer;

final class NoticeSynchronizerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ptadmin-notice-'.bin2hex(random_bytes(5));
        mkdir($this->root.'/src', 0700, true);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        $this->remove($this->root);
    }

    /** @runInSeparateProcess */
    public function testAddonProviderDefinitionsAreSynchronizedWithStableOwner(): void
    {
        file_put_contents($this->root.'/manifest.json', json_encode([
            'code' => 'demo-addon', 'version' => '1.0.0',
            'namespace' => 'NoticeTestAddon',
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->root.'/src/NoticeProvider.php', <<<'PHP'
<?php
namespace NoticeTestAddon;
use PTAdmin\Notice\Contracts\NoticeProviderInterface;
use PTAdmin\Notice\NoticeRegistry;
final class NoticeProvider implements NoticeProviderInterface
{
    public function register(NoticeRegistry $registry): void
    {
        $registry->scene('demo.published', '发布通知')
            ->parameters([['key' => 'title', 'label' => '标题', 'type' => 'string']])
            ->channels(['email'])
            ->supports(['action']);
        $registry->channel('email', '插件邮件')->editor('html');
    }
}
PHP);

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $connection = $capsule->getConnection();
        $schema = $connection->getSchemaBuilder();
        $schema->create('notice_scenes', static function (Blueprint $table): void {
            $table->id();
            $table->string('source_type');
            $table->string('source_code');
            $table->string('group_code');
            $table->string('group_title');
            $table->string('code')->unique();
            $table->string('title');
            $table->string('description')->nullable();
            $table->string('purpose');
            $table->json('parameters')->nullable();
            $table->json('channels')->nullable();
            $table->json('supports')->nullable();
            $table->boolean('record_in_app')->default(false);
            $table->boolean('enabled');
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
        $schema->create('notice_channels', static function (Blueprint $table): void {
            $table->id();
            $table->string('source_type');
            $table->string('source_code');
            $table->string('code')->unique();
            $table->string('type');
            $table->string('title');
            $table->string('editor');
            $table->boolean('enabled');
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
        Container::setInstance(new Container());

        $synchronizer = new NoticeSynchronizer($capsule->getDatabaseManager(), new NoticeRegistry());
        self::assertSame(2, $synchronizer->syncAddon($this->root, 'Demo-Addon'));
        self::assertSame('demo-addon', $connection->table('notice_scenes')->value('source_code'));
        self::assertSame('demo-addon.email', $connection->table('notice_channels')->value('code'));
        self::assertSame('html', $connection->table('notice_channels')->value('editor'));

        $synchronizer->deactivate('addon', 'Demo-Addon');
        self::assertSame(0, (int) $connection->table('notice_scenes')->value('enabled'));
        self::assertSame(0, (int) $connection->table('notice_channels')->value('enabled'));
    }

    public function testCoreSynchronizationRegistersTheBuiltInChannels(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $connection = $capsule->getConnection();
        $schema = $connection->getSchemaBuilder();
        $schema->create('notice_scenes', static function (Blueprint $table): void {
            $table->id();
            $table->string('source_type');
            $table->string('source_code');
            $table->string('code');
            $table->boolean('enabled');
            $table->unsignedInteger('updated_at');
        });
        $schema->create('notice_channels', static function (Blueprint $table): void {
            $table->id();
            $table->string('source_type');
            $table->string('source_code');
            $table->string('code')->unique();
            $table->string('type');
            $table->string('title');
            $table->string('editor');
            $table->boolean('enabled');
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });

        $synchronizer = new NoticeSynchronizer($capsule->getDatabaseManager(), new NoticeRegistry());

        self::assertSame(2, $synchronizer->syncCore());
        self::assertSame('in_app', $connection->table('notice_channels')->where('code', 'ptadmin.in_app')->value('type'));
        self::assertSame('message', $connection->table('notice_channels')->where('code', 'ptadmin.in_app')->value('editor'));
        self::assertSame('email', $connection->table('notice_channels')->where('code', 'ptadmin.email')->value('type'));
        self::assertSame('html', $connection->table('notice_channels')->where('code', 'ptadmin.email')->value('editor'));
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $target = $path.'/'.$entry;
            is_dir($target) ? $this->remove($target) : unlink($target);
        }
        rmdir($path);
    }
}
