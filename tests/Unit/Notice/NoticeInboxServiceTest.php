<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Notice;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use PTAdmin\Notice\NoticeInboxService;
use PTAdmin\Notice\NoticeReceiver;

final class NoticeInboxServiceTest extends TestCase
{
    private ConnectionInterface $database;

    private NoticeInboxService $inbox;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database = $capsule->getConnection();
        $this->database->getSchemaBuilder()->create('notice_messages', static function (Blueprint $table): void {
            $table->id();
            $table->string('receiver_type');
            $table->unsignedBigInteger('receiver_id');
            $table->string('category');
            $table->string('level');
            $table->string('title');
            $table->text('content')->nullable();
            $table->string('action_type');
            $table->string('action_url')->nullable();
            $table->json('data')->nullable();
            $table->unsignedInteger('expires_at')->nullable();
            $table->unsignedInteger('read_at')->nullable();
            $table->unsignedInteger('archived_at')->nullable();
            $table->unsignedInteger('deleted_at')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
        $this->inbox = new NoticeInboxService($this->database);
    }

    public function testInboxOnlyReturnsActiveMessagesForTheReceiver(): void
    {
        $activeId = $this->insertMessage(7, ['title' => '待处理', 'action_type' => 'url', 'action_url' => '/admin/tasks/1']);
        $this->insertMessage(8, ['title' => '其他管理员']);
        $this->insertMessage(7, ['title' => '已归档', 'archived_at' => time()]);
        $this->insertMessage(7, ['title' => '已过期', 'expires_at' => time() - 1]);

        $result = $this->inbox->paginate(NoticeReceiver::admin(7));

        self::assertSame(1, $result['total']);
        self::assertSame($activeId, $result['results'][0]['id']);
        self::assertSame([
            'type' => 'url',
            'target' => '/admin/tasks/1',
            'label' => '查看任务',
        ], $result['results'][0]['action']);
        self::assertSame(1, $this->inbox->unreadCount(NoticeReceiver::admin(7)));
    }

    public function testReadOperationsCannotChangeAnotherReceiversMessages(): void
    {
        $ownId = $this->insertMessage(7, ['title' => '我的消息']);
        $otherId = $this->insertMessage(8, ['title' => '其他消息']);

        self::assertNull($this->inbox->markRead(NoticeReceiver::admin(7), $otherId));
        $marked = $this->inbox->markRead(NoticeReceiver::admin(7), $ownId);
        self::assertNotNull($marked);
        self::assertSame($marked, $this->inbox->markRead(NoticeReceiver::admin(7), $ownId));
        self::assertSame(0, $this->inbox->unreadCount(NoticeReceiver::admin(7)));
        self::assertNull($this->database->table('notice_messages')->where('id', $otherId)->value('read_at'));
    }

    public function testMarkAllReadOnlyUpdatesTheCurrentReceiver(): void
    {
        $this->insertMessage(7, ['title' => '第一条']);
        $this->insertMessage(7, ['title' => '第二条']);
        $otherId = $this->insertMessage(8, ['title' => '其他消息']);

        $result = $this->inbox->markAllRead(NoticeReceiver::admin(7));

        self::assertSame(2, $result['updated']);
        self::assertSame(0, $this->inbox->unreadCount(NoticeReceiver::admin(7)));
        self::assertNull($this->database->table('notice_messages')->where('id', $otherId)->value('read_at'));
    }

    /** @param array<string,mixed> $overrides */
    private function insertMessage(int $receiverId, array $overrides): int
    {
        $now = time();

        return (int) $this->database->table('notice_messages')->insertGetId(array_merge([
            'receiver_type' => 'admin',
            'receiver_id' => $receiverId,
            'category' => 'notice',
            'level' => 'info',
            'title' => '通知',
            'content' => '通知正文',
            'action_type' => 'none',
            'action_url' => null,
            'data' => json_encode(['action_label' => '查看任务'], JSON_THROW_ON_ERROR),
            'expires_at' => null,
            'read_at' => null,
            'archived_at' => null,
            'deleted_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }
}
