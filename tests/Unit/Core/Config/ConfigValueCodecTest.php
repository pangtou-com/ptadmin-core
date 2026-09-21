<?php

declare(strict_types=1);

namespace PTAdmin\Core\Tests\Unit\Core\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PTAdmin\Core\Config\ConfigValueCodec;

final class ConfigValueCodecTest extends TestCase
{
    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'name' => 'runtime',
            'title' => '运行配置',
            'fields' => [
                ['name' => 'enabled', 'type' => 'boolean', 'label' => '是否启用', 'default' => true],
                ['name' => 'mode', 'type' => 'string', 'label' => '运行模式', 'required' => true, 'options' => ['mode' => 'static', 'value_policy' => 'closed', 'items' => [['value' => 'safe', 'label' => '安全']]]],
                ['name' => 'secret', 'type' => 'string', 'label' => '密钥', 'extensions' => ['secret' => ['storage_mode' => 'hash', 'readable' => false]]],
            ],
        ];
    }

    public function testDefaultsAndSecretHashAreAppliedToTheStoredObject(): void
    {
        $codec = new ConfigValueCodec();
        $stored = $codec->encode($this->schema(), ['mode' => 'safe', 'secret' => 'top-secret']);

        self::assertTrue($stored['enabled']);
        self::assertSame(hash('sha256', 'top-secret'), $stored['secret']);
        self::assertSame(['enabled' => true, 'mode' => 'safe'], $codec->decode($this->schema(), $stored));
    }

    public function testUnknownFieldsAndClosedOptionsAreRejected(): void
    {
        $codec = new ConfigValueCodec();

        $this->expectException(InvalidArgumentException::class);
        $codec->encode($this->schema(), ['mode' => 'unsafe']);
    }

    public function testEncodeOverridesDoesNotPersistDefaults(): void
    {
        $codec = new ConfigValueCodec();

        self::assertSame(
            ['mode' => 'safe'],
            $codec->encodeOverrides($this->schema(), ['enabled' => true, 'mode' => 'safe']),
        );
        self::assertSame(
            ['mode' => 'safe'],
            $codec->encodeOverrides($this->schema(), ['enabled' => true, 'mode' => 'safe'], ['enabled' => false, 'mode' => 'safe']),
        );
    }

    public function testStringValuesAcceptScalarValuesFromFormControls(): void
    {
        $codec = new ConfigValueCodec();
        $schema = [
            'name' => 'scalar_values',
            'title' => 'Scalar values',
            'fields' => [
                ['name' => 'number', 'type' => 'string', 'label' => 'Number'],
                ['name' => 'flag', 'type' => 'string', 'label' => 'Flag'],
                ['name' => 'empty', 'type' => 'string', 'label' => 'Empty'],
            ],
        ];

        self::assertSame(
            ['number' => '12', 'flag' => 'false', 'empty' => ''],
            $codec->encode($schema, ['number' => 12, 'flag' => false, 'empty' => null]),
        );
    }

    public function testStringValuesStillRejectObjectsAndArrays(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ConfigValueCodec())->encode([
            'name' => 'invalid_string',
            'title' => 'Invalid string',
            'fields' => [['name' => 'value', 'type' => 'string', 'label' => 'Value']],
        ], ['value' => ['not' => 'a string']]);
    }

    public function testStringDefaultsAreNormalizedBeforeTheyAreApplied(): void
    {
        $schema = [
            'name' => 'default_string',
            'title' => 'Default string',
            'fields' => [['name' => 'value', 'type' => 'string', 'label' => 'Value', 'default' => false]],
        ];

        self::assertSame(['value' => 'false'], (new ConfigValueCodec())->encode($schema, []));
    }
}
