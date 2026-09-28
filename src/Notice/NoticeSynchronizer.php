<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

use Illuminate\Database\DatabaseManager;
use PTAdmin\Notice\Contracts\NoticeProviderInterface;

final class NoticeSynchronizer
{
    public function __construct(private DatabaseManager $database, private NoticeRegistry $registry)
    {
    }

    public function syncCore(): int
    {
        $this->registry->ensureCoreInAppChannel();
        $this->registry->ensureCoreEmailChannel();

        return $this->sync('system', 'ptadmin');
    }

    public function syncAddon(string $directory, string $ownerCode): int
    {
        $provider = $this->loadAddonProvider($directory);
        $this->registry->beginOwner('addon', $ownerCode);
        try {
            $provider?->register($this->registry);
        } finally {
            $this->registry->endOwner();
        }

        return $this->sync('addon', $ownerCode);
    }

    public function deactivate(string $sourceType, string $sourceCode): void
    {
        $owner = ['source_type' => $sourceType, 'source_code' => strtolower($sourceCode)];
        $database = $this->database->connection();
        $database->table('notice_scenes')->where($owner)->update(['enabled' => false, 'updated_at' => time()]);
        $database->table('notice_channels')->where($owner)->update(['enabled' => false, 'updated_at' => time()]);
    }

    private function sync(string $sourceType, string $sourceCode): int
    {
        $sourceCode = strtolower(trim($sourceCode));
        return $this->database->connection()->transaction(function () use ($sourceType, $sourceCode): int {
            $database = $this->database->connection();
            $scenes = $this->registry->scenesFor($sourceType, $sourceCode);
            $channels = $this->registry->channelsFor($sourceType, $sourceCode);
            $codes = [];
            foreach ($scenes as $scene) {
                $code = strtolower(trim((string) ($scene['code'] ?? '')));
                if ($code === '' || isset($codes[$code])) {
                    throw new NoticeDefinitionException('通知场景编码无效或重复：'.$code);
                }
                $this->assertScene($scene, $code);
                $codes[$code] = true;
                $existing = $database->table('notice_scenes')->where('code', $code)->first();
                if ($existing && ((string) $existing->source_type !== $sourceType || (string) $existing->source_code !== $sourceCode)) {
                    throw new NoticeDefinitionException('通知场景编码已属于其他来源：'.$code, 'notice_owner_conflict');
                }
                $attributes = [
                    'source_type' => $sourceType,
                    'source_code' => $sourceCode,
                    'group_code' => 'general',
                    'group_title' => '常规通知',
                    'title' => (string) $scene['title'],
                    'description' => (string) ($scene['description'] ?? ''),
                    'purpose' => (string) ($scene['purpose'] ?? 'transactional'),
                    'parameters' => json_encode($scene['parameters'] ?? [], JSON_THROW_ON_ERROR),
                    'channels' => json_encode($scene['channels'] ?? [], JSON_THROW_ON_ERROR),
                    'supports' => json_encode($scene['supports'] ?? [], JSON_THROW_ON_ERROR),
                    'record_in_app' => (bool) ($scene['record_in_app'] ?? false),
                    'enabled' => true,
                    'updated_at' => time(),
                ];
                if (!$existing) {
                    $attributes['created_at'] = time();
                }
                $database->table('notice_scenes')->updateOrInsert(['code' => $code], $attributes);
            }
            $query = $database->table('notice_scenes')->where(['source_type' => $sourceType, 'source_code' => $sourceCode]);
            if ($codes !== []) {
                $query->whereNotIn('code', array_keys($codes));
            }
            $query->update(['enabled' => false, 'updated_at' => time()]);
            $registeredChannels = [];
            foreach ($channels as $channel) {
                $channelCode = trim((string) ($channel['code'] ?? ''));
                $editor = (string) ($channel['editor'] ?? '');
                $type = (string) ($channel['type'] ?? '');
                if (preg_match('/^[a-z][a-z0-9_.-]{1,149}$/', $channelCode) !== 1
                    || preg_match('/^[a-z][a-z0-9_]{1,29}$/', $type) !== 1
                    || trim((string) ($channel['title'] ?? '')) === ''
                    || !in_array($editor, ['template', 'content', 'message', 'html'], true)
                    || isset($registeredChannels[$channelCode])
                ) {
                    throw new NoticeDefinitionException('发送渠道定义无效：'.$channelCode);
                }
                if ($type === 'in_app' && ($sourceType !== 'system' || $channelCode !== NoticeChannel::IN_APP_CODE)) {
                    throw new NoticeDefinitionException('站内信渠道由 Core 内置，插件不能重复注册。', 'notice_in_app_channel_reserved');
                }
                $registeredChannels[$channelCode] = true;
                $existing = $database->table('notice_channels')->where('code', $channelCode)->first();
                if ($existing && ((string) $existing->source_type !== $sourceType || (string) $existing->source_code !== $sourceCode)) {
                    throw new NoticeDefinitionException('发送渠道编码已属于其他来源：'.$channelCode, 'notice_owner_conflict');
                }
                $attributes = [
                    'source_type' => $sourceType,
                    'source_code' => $sourceCode,
                    'type' => $type,
                    'title' => (string) $channel['title'],
                    'editor' => (string) $channel['editor'],
                    'enabled' => true,
                    'updated_at' => time(),
                ];
                if (!$existing) {
                    $attributes['created_at'] = time();
                }
                $database->table('notice_channels')->updateOrInsert(['code' => $channelCode], $attributes);
            }
            $channelCodes = array_column($channels, 'code');
            $staleChannels = $database->table('notice_channels')->where([
                'source_type' => $sourceType,
                'source_code' => $sourceCode,
            ]);
            if ($channelCodes !== []) {
                $staleChannels->whereNotIn('code', $channelCodes);
            }
            $staleChannels->update(['enabled' => false, 'updated_at' => time()]);

            return count($scenes) + count($channels);
        });
    }

    /** @param array<string, mixed> $scene */
    private function assertScene(array $scene, string $code): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,99}$/', $code) !== 1 || trim((string) ($scene['title'] ?? '')) === '') {
            throw new NoticeDefinitionException('通知场景定义无效：'.$code);
        }
        $parameterKeys = [];
        foreach ((array) ($scene['parameters'] ?? []) as $parameter) {
            if (!is_array($parameter) || array_is_list($parameter)) {
                throw new NoticeDefinitionException('通知场景参数必须是对象：'.$code);
            }
            $key = (string) ($parameter['key'] ?? '');
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1 || isset($parameterKeys[$key])) {
                throw new NoticeDefinitionException('通知场景参数编码无效或重复：'.$code.'.'.$key);
            }
            if (trim((string) ($parameter['label'] ?? '')) === '' || !in_array(($parameter['type'] ?? null), ['string', 'number', 'boolean'], true)) {
                throw new NoticeDefinitionException('通知场景参数定义无效：'.$code.'.'.$key);
            }
            $parameterKeys[$key] = true;
        }
        foreach ((array) ($scene['channels'] ?? []) as $channel) {
            if (!is_string($channel) || preg_match('/^[a-z][a-z0-9_]{1,29}$/', $channel) !== 1) {
                throw new NoticeDefinitionException('通知场景渠道类型无效：'.$code);
            }
        }
        foreach ((array) ($scene['supports'] ?? []) as $support) {
            if ($support !== 'action') {
                throw new NoticeDefinitionException('通知场景能力暂不支持：'.$code.'.'.(string) $support);
            }
        }
        if ((bool) ($scene['record_in_app'] ?? false)) {
            $channels = (array) ($scene['channels'] ?? []);
            if ($channels !== [] && !in_array('in_app', $channels, true)) {
                throw new NoticeDefinitionException('自动记录站内信的场景必须允许 in_app 渠道：'.$code);
            }
        }
    }

    private function loadAddonProvider(string $directory): ?NoticeProviderInterface
    {
        $directory = rtrim($directory, '/');
        $path = $directory.'/Resources/NoticeProvider.php';
        if (!is_file($path)) {
            return null;
        }
        $manifest = json_decode((string) file_get_contents($directory.'/manifest.json'), true);
        $namespace = is_array($manifest) ? trim((string) ($manifest['namespace'] ?? ''), '\\') : '';
        $class = $namespace !== '' ? $namespace.'\\NoticeProvider' : '';
        if ($class === '' || !class_exists($class)) {
            require_once $path;
        }
        if ($class === '' || !class_exists($class)) {
            throw new NoticeDefinitionException('插件通知 Provider 类不存在。', 'notice_provider_invalid');
        }
        $provider = app()->make($class);
        if (!$provider instanceof NoticeProviderInterface) {
            throw new NoticeDefinitionException('插件通知 Provider 必须实现 NoticeProviderInterface。', 'notice_provider_invalid');
        }

        return $provider;
    }
}
