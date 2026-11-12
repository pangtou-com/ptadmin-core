<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Notice;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use PTAdmin\Notice\Contracts\NoticeChannelAdapterInterface;
use PTAdmin\Notice\Contracts\NoticeChannelBatchAdapterInterface;
use PTAdmin\Notice\Contracts\NoticeChannelQueryAdapterInterface;
use PTAdmin\Notice\Contracts\NoticeChannelReceiptAdapterInterface;
use PTAdmin\Notice\NoticeChannelBatchResponse;
use PTAdmin\Notice\Contracts\NoticeChannelRoutingHookInterface;
use PTAdmin\Notice\Notice;
use PTAdmin\Notice\NoticeChannelAdapterRegistry;
use PTAdmin\Notice\NoticeChannelMessage;
use PTAdmin\Notice\NoticeChannelResponse;
use PTAdmin\Notice\NoticeChannelRouter;
use PTAdmin\Notice\NoticeManager;
use PTAdmin\Notice\NoticeDeliveryQuery;
use PTAdmin\Notice\NoticeDeliveryService;
use PTAdmin\Notice\NoticeReceiver;
use PTAdmin\Notice\NoticeRoutingContext;
use PTAdmin\Notice\NoticeSendingException;
use PTAdmin\Notice\NoticeReceipt;
use PTAdmin\Notice\NoticeTarget;
use PTAdmin\Notice\NoticeTemplateResolver;

final class NoticeManagerTest extends TestCase
{
    private ConnectionInterface $database;

    private FakeNoticeChannelAdapter $sms;

    private FakeNoticeChannelAdapter $backupSms;

    private FakeNoticeChannelAdapter $email;

    private NoticeChannelAdapterRegistry $adapters;

    private NoticeChannelRouter $router;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database = $capsule->getConnection();
        $this->createTables();
        $this->seedDefinitions();

        $container = new Container();
        $this->adapters = new NoticeChannelAdapterRegistry($container);
        $this->sms = new FakeNoticeChannelAdapter(NoticeChannelResponse::delivered('sms-1', ['provider' => 'demo']));
        $this->backupSms = new FakeNoticeChannelAdapter(NoticeChannelResponse::accepted('sms-2'));
        $this->email = new FakeNoticeChannelAdapter(NoticeChannelResponse::delivered('email-1'));
        $this->adapters->register('demo.sms', $this->sms);
        $this->adapters->register('backup.sms', $this->backupSms);
        $this->adapters->register('demo.email', $this->email);
        $this->router = new NoticeChannelRouter();
        $manager = new NoticeManager(
            $this->database,
            new NoticeTemplateResolver($this->database),
            $this->adapters,
            $this->router,
        );
        $container->instance(NoticeManager::class, $manager);
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    public function testExplicitChannelUsesAdapterAndRecordsExternalDelivery(): void
    {
        $result = Notice::send(
            scene: 'security.login_warning',
            channel: 'demo.sms',
            to: '13800138000',
            parameters: ['name' => '管理员'],
        );

        self::assertTrue($result->successful());
        self::assertSame('delivered', $result->targets[0]->status);
        self::assertSame('sms-1', $result->targets[0]->providerMessageId);
        self::assertCount(1, $this->sms->messages);
        self::assertSame('13800138000', $this->sms->messages[0]->destination);
        self::assertSame('管理员，发现异常登录。', $this->sms->messages[0]->template->config['content']);
        self::assertSame(0, $this->database->table('notice_messages')->count());
        self::assertSame('delivered', $this->database->table('notice_deliveries')->value('status'));
        self::assertSame('sms-1', $this->database->table('notice_deliveries')->value('provider_message_id'));
        self::assertSame(
            ['provider' => 'demo'],
            json_decode((string) $this->database->table('notice_deliveries')->value('meta'), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testTypedChannelDefaultsToFirstCandidateAndRoutingHookCanOverrideIt(): void
    {
        $default = Notice::sendSMS('security.login_warning', '13800138000', ['name' => '管理员']);

        self::assertSame('demo.sms', $default->targets[0]->channel);
        self::assertCount(1, $this->sms->messages);

        $this->router->register(new SelectNoticeChannelHook('backup.sms'), 100);
        $routed = Notice::sendSMS('security.login_warning', '13800138001', ['name' => '值班员']);

        self::assertSame('backup.sms', $routed->targets[0]->channel);
        self::assertSame('accepted', $routed->targets[0]->status);
        self::assertCount(1, $this->backupSms->messages);
    }

    public function testMultiChannelSendAutomaticallyRecordsOneLinkedInAppMessage(): void
    {
        $this->database->table('notice_scenes')->update(['record_in_app' => true]);
        $receiver = NoticeReceiver::admin(9);

        $result = Notice::sendMany(
            scene: 'security.login_warning',
            targets: [
                NoticeTarget::sms('13800138000'),
                NoticeTarget::email('admin@example.com'),
            ],
            parameters: ['name' => '管理员'],
            receiver: $receiver,
        );

        self::assertTrue($result->successful());
        self::assertCount(3, $result->targets);
        self::assertSame(1, $this->database->table('notice_messages')->count());
        self::assertSame(2, $this->database->table('notice_deliveries')->count());
        $messageId = (int) $this->database->table('notice_messages')->value('id');
        self::assertSame('管理员，你有一条安全提醒。', $this->database->table('notice_messages')->value('content'));
        self::assertSame(
            [$messageId, $messageId],
            $this->database->table('notice_deliveries')->orderBy('id')->pluck('notice_message_id')->all(),
        );
    }

    public function testExplicitInAppTargetIsNotDuplicatedByRecordInApp(): void
    {
        $this->database->table('notice_scenes')->update(['record_in_app' => true]);

        $result = Notice::sendInApp(
            'security.login_warning',
            NoticeReceiver::admin(9),
            ['name' => '管理员'],
        );

        self::assertTrue($result->successful());
        self::assertCount(1, $result->targets);
        self::assertSame(1, $this->database->table('notice_messages')->count());
        self::assertSame(0, $this->database->table('notice_deliveries')->count());
    }

    public function testAllTargetsAreValidatedBeforeAnyMessageIsSent(): void
    {
        $sceneId = (int) $this->database->table('notice_scenes')->value('id');
        $this->database->table('notice_channels')->insert([
            'code' => 'missing.email',
            'type' => 'email',
            'editor' => 'content',
            'enabled' => true,
        ]);
        $this->database->table('notice_templates')->insert([
            'scene_id' => $sceneId,
            'channel' => 'missing.email',
            'locale' => 'zh-CN',
            'mode' => 'content',
            'config' => json_encode(['content' => '{{name}}'], JSON_THROW_ON_ERROR),
            'enabled' => true,
        ]);

        try {
            Notice::sendMany(
                'security.login_warning',
                [NoticeTarget::sms('13800138000'), NoticeTarget::email('admin@example.com', 'missing.email')],
                ['name' => '管理员'],
            );
            self::fail('A missing adapter must fail before dispatch starts.');
        } catch (NoticeSendingException $exception) {
            self::assertSame('notice_channel_adapter_missing', $exception->errorCode);
        }

        self::assertCount(0, $this->sms->messages);
        self::assertSame(0, $this->database->table('notice_messages')->count());
        self::assertSame(0, $this->database->table('notice_deliveries')->count());
    }

    public function testAdapterFailureReturnsFailedTargetAndPersistsFailure(): void
    {
        $this->sms->response = NoticeChannelResponse::failed('provider_rejected', '服务商拒绝发送');

        $result = Notice::sendSMS('security.login_warning', '13800138000', ['name' => '管理员']);

        self::assertFalse($result->successful());
        self::assertSame('failed', $result->targets[0]->status);
        self::assertSame('provider_rejected', $result->targets[0]->errorCode);
        self::assertSame('failed', $this->database->table('notice_deliveries')->value('status'));
        self::assertSame('服务商拒绝发送', $this->database->table('notice_deliveries')->value('error_message'));
    }

    public function testBatchSendCreatesIndependentDeliveriesAndQueryAndReceiptUpdateThem(): void
    {
        $result = Notice::sendSMSBatch('security.login_warning', ['13800138000', '13800138001'], ['name' => '管理员'], 'demo.sms');

        self::assertTrue($result->successful());
        self::assertCount(2, $result->targets);
        self::assertSame(2, $this->database->table('notice_deliveries')->count());
        self::assertSame(1, $this->database->table('notice_deliveries')->distinct('batch_key')->count('batch_key'));
        self::assertSame(['13800138000', '13800138001'], $this->database->table('notice_deliveries')->orderBy('id')->pluck('destination')->all());

        $delivery = $this->database->table('notice_deliveries')->orderBy('id')->first();
        $deliveryService = new NoticeDeliveryService($this->database, $this->adapters);
        $queried = $deliveryService->query((int) $delivery->id);
        self::assertSame('delivered', $queried->status);

        self::assertSame(1, $deliveryService->applyReceipt('demo.sms', '{}'));
        self::assertSame('delivered', $this->database->table('notice_deliveries')->where('id', $delivery->id)->value('status'));
    }

    public function testBatchFallbackKeepsEarlierSuccessWhenLaterSingleSendFails(): void
    {
        $sceneId = (int) $this->database->table('notice_scenes')->value('id');
        $adapter = new SingleOnlyNoticeChannelAdapter();
        $this->adapters->register('single.sms', $adapter);
        $this->database->table('notice_channels')->insert([
            'code' => 'single.sms',
            'type' => 'sms',
            'editor' => 'content',
            'enabled' => true,
        ]);
        $this->database->table('notice_templates')->insert([
            'scene_id' => $sceneId,
            'channel' => 'single.sms',
            'locale' => 'zh-CN',
            'mode' => 'content',
            'config' => json_encode(['content' => '{{name}}，短信提醒。'], JSON_THROW_ON_ERROR),
            'enabled' => true,
        ]);

        $result = Notice::sendSMSBatch(
            'security.login_warning',
            ['13800138000', '13800138001'],
            ['name' => '管理员'],
            'single.sms',
        );

        self::assertFalse($result->successful());
        self::assertSame(['accepted', 'failed'], array_map(static fn ($target): string => $target->status, $result->targets));
        self::assertSame(['accepted', 'failed'], $this->database->table('notice_deliveries')->orderBy('id')->pluck('status')->all());
    }

    public function testReceiptsRequireMessageIdAndUniqueMatchingDestination(): void
    {
        Notice::sendSMSBatch('security.login_warning', ['13800138000', '13800138001'], ['name' => '管理员'], 'demo.sms');
        $this->database->table('notice_deliveries')->update(['provider_message_id' => 'shared']);
        $service = new NoticeDeliveryService($this->database, $this->adapters);
        $this->sms->receipts = [new NoticeReceipt(null, '13800138000', 'delivered'), new NoticeReceipt('shared', null, 'delivered')];
        self::assertSame(0, $service->applyReceipt('demo.sms', '[]'));
        $this->sms->receipts = [new NoticeReceipt('shared', '13900139000', 'delivered')];
        self::assertSame(0, $service->applyReceipt('demo.sms', '[]'));
        $this->sms->receipts = [new NoticeReceipt('shared', '13800138000', 'delivered', 1000)];
        self::assertSame(1, $service->applyReceipt('demo.sms', '[]'));
        self::assertSame(['delivered', 'accepted'], $this->database->table('notice_deliveries')->orderBy('id')->pluck('status')->all());
        $before = (array) $this->database->table('notice_deliveries')->where('id', 1)->first();
        $this->sms->receipts = [new NoticeReceipt('shared', '13800138000', 'delivered', 2000), new NoticeReceipt('shared', '13800138000', 'failed')];
        $service->applyReceipt('demo.sms', '[]');
        self::assertSame($before, (array) $this->database->table('notice_deliveries')->where('id', 1)->first());
    }

    public function testQueryCannotOverwriteReceiptCommittedWhileProviderIsResponding(): void
    {
        Notice::sendSMSBatch('security.login_warning', ['13800138000'], ['name' => '管理员'], 'demo.sms');
        $service = new NoticeDeliveryService($this->database, $this->adapters);
        $this->sms->queryCallback = static function () use ($service): NoticeChannelResponse {
            $service->applyReceipt('demo.sms', '[]');
            return NoticeChannelResponse::failed('late_failure', '迟到的查询结果');
        };
        self::assertSame('delivered', $service->query(1)->status);
        self::assertNull($this->database->table('notice_deliveries')->where('id', 1)->value('error_code'));
    }

    public function testUnknownQueryPreservesFailureDetails(): void
    {
        Notice::sendSMSBatch('security.login_warning', ['13800138000'], ['name' => '管理员'], 'demo.sms');
        $service = new NoticeDeliveryService($this->database, $this->adapters);
        $this->sms->receipts = [new NoticeReceipt('batch-1', '13800138000', 'failed', errorCode: 'provider_failure', errorMessage: '真实失败原因')];
        $service->applyReceipt('demo.sms', '[]');
        $before = (array) $this->database->table('notice_deliveries')->where('id', 1)->first();
        $this->sms->queryCallback = static fn (): NoticeChannelResponse => NoticeChannelResponse::unknown();
        $result = $service->query(1);
        self::assertSame('provider_failure', $result->errorCode);
        self::assertSame($before, (array) $this->database->table('notice_deliveries')->where('id', 1)->first());
    }

    private function createTables(): void
    {
        $schema = $this->database->getSchemaBuilder();
        $schema->create('notice_scenes', static function (Blueprint $table): void {
            $table->id();
            $table->string('source_type');
            $table->string('source_code');
            $table->string('code');
            $table->json('parameters')->nullable();
            $table->json('channels')->nullable();
            $table->json('supports')->nullable();
            $table->boolean('record_in_app')->default(false);
            $table->boolean('enabled');
        });
        $schema->create('notice_channels', static function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('type');
            $table->string('editor');
            $table->boolean('enabled');
        });
        $schema->create('notice_templates', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scene_id');
            $table->string('channel');
            $table->string('locale');
            $table->string('mode');
            $table->json('config')->nullable();
            $table->boolean('enabled');
        });
        $schema->create('notice_messages', static function (Blueprint $table): void {
            $table->id();
            $table->string('receiver_type');
            $table->unsignedBigInteger('receiver_id');
            $table->string('source_type');
            $table->string('source_code');
            $table->string('category');
            $table->string('level');
            $table->string('title');
            $table->text('content')->nullable();
            $table->string('action_type');
            $table->string('action_url')->nullable();
            $table->string('biz_type')->nullable();
            $table->string('biz_id')->nullable();
            $table->string('biz_key')->nullable();
            $table->json('data')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedInteger('expires_at')->nullable();
            $table->unsignedInteger('read_at')->nullable();
            $table->unsignedInteger('archived_at')->nullable();
            $table->unsignedInteger('deleted_at')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
        $schema->create('notice_deliveries', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('notice_message_id')->nullable();
            $table->string('scene_code');
            $table->string('channel');
            $table->string('destination')->nullable();
            $table->string('batch_key')->nullable();
            $table->string('receiver_type')->nullable();
            $table->unsignedBigInteger('receiver_id')->nullable();
            $table->string('status');
            $table->string('provider_message_id')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('accepted_at')->nullable();
            $table->unsignedInteger('delivered_at')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
    }

    private function seedDefinitions(): void
    {
        $sceneId = (int) $this->database->table('notice_scenes')->insertGetId([
            'source_type' => 'system',
            'source_code' => 'ptadmin',
            'code' => 'security.login_warning',
            'parameters' => json_encode([['key' => 'name', 'label' => '名称', 'type' => 'string']], JSON_THROW_ON_ERROR),
            'channels' => json_encode(['sms', 'email', 'in_app'], JSON_THROW_ON_ERROR),
            'supports' => json_encode([], JSON_THROW_ON_ERROR),
            'record_in_app' => false,
            'enabled' => true,
        ]);
        $this->database->table('notice_channels')->insert([
            ['code' => 'demo.sms', 'type' => 'sms', 'editor' => 'content', 'enabled' => true],
            ['code' => 'backup.sms', 'type' => 'sms', 'editor' => 'content', 'enabled' => true],
            ['code' => 'demo.email', 'type' => 'email', 'editor' => 'content', 'enabled' => true],
            ['code' => 'ptadmin.in_app', 'type' => 'in_app', 'editor' => 'message', 'enabled' => true],
        ]);
        $templates = [
            'demo.sms' => ['mode' => 'content', 'config' => ['content' => '{{name}}，发现异常登录。']],
            'backup.sms' => ['mode' => 'content', 'config' => ['content' => '{{name}}，发现异常登录。']],
            'demo.email' => ['mode' => 'content', 'config' => ['content' => '{{name}}，发现异常登录。']],
            'ptadmin.in_app' => ['mode' => 'message', 'config' => ['title' => '安全提醒', 'content' => '{{name}}，你有一条安全提醒。']],
        ];
        foreach ($templates as $channel => $template) {
            $this->database->table('notice_templates')->insert([
                'scene_id' => $sceneId,
                'channel' => $channel,
                'locale' => 'zh-CN',
                'mode' => $template['mode'],
                'config' => json_encode($template['config'], JSON_THROW_ON_ERROR),
                'enabled' => true,
            ]);
        }
    }
}

final class FakeNoticeChannelAdapter implements NoticeChannelAdapterInterface, NoticeChannelBatchAdapterInterface, NoticeChannelQueryAdapterInterface, NoticeChannelReceiptAdapterInterface
{
    /** @var list<NoticeChannelMessage> */
    public array $messages = [];

    /** @var list<NoticeReceipt>|null */
    public ?array $receipts = null;

    public ?\Closure $queryCallback = null;

    public function __construct(public NoticeChannelResponse $response)
    {
    }

    public function send(NoticeChannelMessage $message): NoticeChannelResponse
    {
        $this->messages[] = $message;

        return $this->response;
    }

    /** @param list<NoticeChannelMessage> $messages */
    public function sendBatch(array $messages): NoticeChannelBatchResponse
    {
        $responses = array_map(
            static fn (NoticeChannelMessage $message): NoticeChannelResponse => NoticeChannelResponse::accepted('batch-'.$message->deliveryId),
            $messages,
        );

        return NoticeChannelBatchResponse::completed($responses, 'batch-group');
    }

    public function query(NoticeDeliveryQuery $query): NoticeChannelResponse
    {
        return $this->queryCallback !== null ? ($this->queryCallback)() : NoticeChannelResponse::delivered($query->providerMessageId);
    }

    /** @param array<string, string|list<string>> $headers @return list<NoticeReceipt> */
    public function parseReceipt(string $body, array $headers = []): array
    {
        return $this->receipts ?? [new NoticeReceipt('batch-1', '13800138000', 'delivered')];
    }
}

final class SingleOnlyNoticeChannelAdapter implements NoticeChannelAdapterInterface
{
    private int $calls = 0;

    public function send(NoticeChannelMessage $message): NoticeChannelResponse
    {
        $this->calls++;
        if ($this->calls === 2) {
            throw new \RuntimeException('第二个目标发送失败');
        }

        return NoticeChannelResponse::accepted('single-'.$message->deliveryId);
    }
}

final class SelectNoticeChannelHook implements NoticeChannelRoutingHookInterface
{
    public function __construct(private string $channel)
    {
    }

    public function select(NoticeRoutingContext $context, array $candidates): ?string
    {
        return $this->channel;
    }
}
