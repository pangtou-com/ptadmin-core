<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Notice;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use PTAdmin\Notice\NoticeResolutionException;
use PTAdmin\Notice\NoticeTemplate;
use PTAdmin\Notice\NoticeTemplateResolver;

final class NoticeTemplateResolverTest extends TestCase
{
    private ConnectionInterface $database;

    private NoticeTemplateResolver $resolver;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database = $capsule->getConnection();
        $schema = $this->database->getSchemaBuilder();
        $schema->create('notice_scenes', static function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->json('parameters')->nullable();
            $table->json('channels')->nullable();
            $table->json('supports')->nullable();
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

        $sceneId = $this->database->table('notice_scenes')->insertGetId([
            'code' => 'auth.verify_code',
            'parameters' => json_encode([
                ['key' => 'name', 'label' => '姓名', 'type' => 'string'],
                ['key' => 'code', 'label' => '验证码', 'type' => 'number'],
                ['key' => 'urgent', 'label' => '紧急通知', 'type' => 'boolean'],
            ], JSON_THROW_ON_ERROR),
            'channels' => json_encode(['sms'], JSON_THROW_ON_ERROR),
            'supports' => json_encode(['action'], JSON_THROW_ON_ERROR),
            'enabled' => true,
        ]);
        $this->database->table('notice_channels')->insert([
            'code' => 'demo.sms',
            'type' => 'sms',
            'editor' => 'content',
            'enabled' => true,
        ]);
        $this->database->table('notice_templates')->insert([
            'scene_id' => $sceneId,
            'channel' => 'demo.sms',
            'locale' => 'zh-CN',
            'mode' => 'content',
            'config' => json_encode(['content' => '{{name}}，验证码为 {{ code }}，紧急：{{urgent}}。'], JSON_THROW_ON_ERROR),
            'enabled' => true,
        ]);

        $this->resolver = new NoticeTemplateResolver($this->database);
        $container = new Container();
        $container->instance(NoticeTemplateResolver::class, $this->resolver);
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    public function testStaticEntryResolvesExactTemplateAndAction(): void
    {
        $resolved = NoticeTemplate::resolve(
            scene: 'auth.verify_code',
            channel: 'demo.sms',
            parameters: ['name' => '小明', 'code' => 123456, 'urgent' => true],
            action: ['type' => 'url', 'target' => ' /admin/security/1 ', 'label' => ' 查看 '],
        );

        self::assertSame('content', $resolved->editor);
        self::assertSame('小明，验证码为 123456，紧急：true。', $resolved->config['content']);
        self::assertSame(
            ['type' => 'url', 'target' => '/admin/security/1', 'label' => '查看'],
            $resolved->action,
        );
    }

    public function testHtmlParametersAreEscapedWhileTextFieldsRemainPlain(): void
    {
        $this->database->table('notice_channels')->where('code', 'demo.sms')->update(['editor' => 'html']);
        $this->database->table('notice_templates')->update([
            'mode' => 'html',
            'config' => json_encode([
                'subject' => '你好，{{name}}',
                'html' => '<p>{{name}}</p>',
                'text' => '{{name}}',
            ], JSON_THROW_ON_ERROR),
        ]);

        $resolved = $this->resolver->resolve('auth.verify_code', 'demo.sms', parameters: ['name' => '<Admin>']);

        self::assertSame('你好，<Admin>', $resolved->config['subject']);
        self::assertSame('<p>&lt;Admin&gt;</p>', $resolved->config['html']);
        self::assertSame('<Admin>', $resolved->config['text']);
    }

    public function testThirdPartyTemplateMapsExternalParametersAndKeepsChannelConfiguration(): void
    {
        $this->database->table('notice_channels')->where('code', 'demo.sms')->update(['editor' => 'template']);
        $this->database->table('notice_templates')->update([
            'mode' => 'template',
            'config' => json_encode([
                'template_code' => 'SMS_VERIFY',
                'bindings' => ['user' => 'name', 'verify_code' => 'code'],
                'sign_name' => 'PTAdmin',
            ], JSON_THROW_ON_ERROR),
        ]);

        $resolved = $this->resolver->resolve(
            'auth.verify_code',
            'demo.sms',
            parameters: ['name' => '小明', 'code' => 123456],
        );

        self::assertSame('SMS_VERIFY', $resolved->config['template_code']);
        self::assertSame('PTAdmin', $resolved->config['sign_name']);
        self::assertSame(['user' => '小明', 'verify_code' => 123456], $resolved->config['parameters']);
        self::assertArrayNotHasKey('bindings', $resolved->config);
    }

    public function testResolverRejectsUndeclaredAndInvalidParameterTypes(): void
    {
        try {
            $this->resolver->resolve('auth.verify_code', 'demo.sms', parameters: ['unknown' => 'value']);
            self::fail('Undeclared parameters must be rejected.');
        } catch (NoticeResolutionException $exception) {
            self::assertSame('notice_parameter_not_declared', $exception->errorCode);
        }

        try {
            $this->resolver->resolve('auth.verify_code', 'demo.sms', parameters: ['code' => '123456']);
            self::fail('Invalid parameter types must be rejected.');
        } catch (NoticeResolutionException $exception) {
            self::assertSame('notice_parameter_type_invalid', $exception->errorCode);
        }
    }

    public function testResolverRejectsMissingTemplateParametersAndDoesNotFallbackLocale(): void
    {
        try {
            $this->resolver->resolve('auth.verify_code', 'demo.sms', parameters: ['name' => '小明']);
            self::fail('Missing parameters referenced by the template must be rejected.');
        } catch (NoticeResolutionException $exception) {
            self::assertSame('notice_parameter_missing', $exception->errorCode);
            self::assertSame('code', $exception->details['parameter']);
        }

        try {
            $this->resolver->resolve('auth.verify_code', 'demo.sms', locale: 'en-US');
            self::fail('Locale resolution must require an exact template.');
        } catch (NoticeResolutionException $exception) {
            self::assertSame('notice_template_not_found', $exception->errorCode);
        }
    }

    public function testResolverRejectsDisallowedChannelAndUnsupportedAction(): void
    {
        $this->database->table('notice_scenes')->update([
            'channels' => json_encode(['email'], JSON_THROW_ON_ERROR),
        ]);
        try {
            $this->resolver->resolve('auth.verify_code', 'demo.sms');
            self::fail('Channels outside the scene allowlist must be rejected.');
        } catch (NoticeResolutionException $exception) {
            self::assertSame('notice_channel_not_allowed', $exception->errorCode);
        }

        $this->database->table('notice_scenes')->update([
            'channels' => json_encode([], JSON_THROW_ON_ERROR),
            'supports' => json_encode([], JSON_THROW_ON_ERROR),
        ]);
        try {
            $this->resolver->resolve(
                'auth.verify_code',
                'demo.sms',
                action: ['type' => 'url', 'target' => '/admin/security/1'],
            );
            self::fail('Actions must be declared by the scene.');
        } catch (NoticeResolutionException $exception) {
            self::assertSame('notice_action_not_supported', $exception->errorCode);
        }
    }

    public function testResolverRejectsDisabledSceneChannelAndTemplate(): void
    {
        $this->database->table('notice_scenes')->update(['enabled' => false]);
        $this->assertResolutionError('notice_scene_disabled');

        $this->database->table('notice_scenes')->update(['enabled' => true]);
        $this->database->table('notice_channels')->update(['enabled' => false]);
        $this->assertResolutionError('notice_channel_disabled');

        $this->database->table('notice_channels')->update(['enabled' => true]);
        $this->database->table('notice_templates')->update(['enabled' => false]);
        $this->assertResolutionError('notice_template_disabled');
    }

    private function assertResolutionError(string $errorCode): void
    {
        try {
            $this->resolver->resolve('auth.verify_code', 'demo.sms');
            self::fail('Expected notice resolution to fail.');
        } catch (NoticeResolutionException $exception) {
            self::assertSame($errorCode, $exception->errorCode);
        }
    }
}
