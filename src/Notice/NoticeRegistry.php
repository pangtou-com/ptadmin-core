<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

final class NoticeRegistry
{
    /** @var array<string, list<NoticeSceneDefinitionBuilder>> */
    private array $scenes = [];

    /** @var array<string, list<NoticeChannelDefinitionBuilder>> */
    private array $channels = [];

    private string $sourceType = 'system';

    private string $sourceCode = 'ptadmin';

    public function beginOwner(string $sourceType, string $sourceCode): void
    {
        if (!in_array($sourceType, ['system', 'addon'], true) || trim($sourceCode) === '') {
            throw new NoticeDefinitionException('通知定义 owner 无效。', 'notice_owner_invalid');
        }
        $this->sourceType = $sourceType;
        $this->sourceCode = strtolower(trim($sourceCode));
        $this->scenes[$this->ownerKey()] = [];
        $this->channels[$this->ownerKey()] = [];
    }

    public function endOwner(): void
    {
        $this->sourceType = 'system';
        $this->sourceCode = 'ptadmin';
    }

    public function scene(string $code, string $title): NoticeSceneDefinitionBuilder
    {
        $builder = new NoticeSceneDefinitionBuilder($code, $title, $this->sourceType, $this->sourceCode);
        $this->scenes[$this->ownerKey()][] = $builder;

        return $builder;
    }

    public function channel(string $type, string $title): NoticeChannelDefinitionBuilder
    {
        $builder = new NoticeChannelDefinitionBuilder($type, $title, $this->sourceType, $this->sourceCode);
        $this->channels[$this->ownerKey()][] = $builder;

        return $builder;
    }

    public function ensureCoreInAppChannel(): void
    {
        $owner = 'system:ptadmin';
        foreach ($this->channels[$owner] ?? [] as $channel) {
            if (($channel->toArray()['code'] ?? null) === NoticeChannel::IN_APP_CODE) {
                return;
            }
        }
        $this->channels[$owner][] = (new NoticeChannelDefinitionBuilder('in_app', '后台站内信', 'system', 'ptadmin'))
            ->code(NoticeChannel::IN_APP_CODE)
            ->editor('message');
    }

    /** @return list<array<string, mixed>> */
    public function scenesFor(string $sourceType, string $sourceCode): array
    {
        return array_map(static fn (NoticeSceneDefinitionBuilder $builder): array => $builder->toArray(), $this->scenes[$sourceType.':'.strtolower($sourceCode)] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function channelsFor(string $sourceType, string $sourceCode): array
    {
        return array_map(static fn (NoticeChannelDefinitionBuilder $builder): array => $builder->toArray(), $this->channels[$sourceType.':'.strtolower($sourceCode)] ?? []);
    }

    private function ownerKey(): string
    {
        return $this->sourceType.':'.$this->sourceCode;
    }
}
