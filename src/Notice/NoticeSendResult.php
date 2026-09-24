<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

final class NoticeSendResult
{
    /** @param list<NoticeTargetResult> $targets */
    public function __construct(
        public readonly string $scene,
        public readonly string $locale,
        public readonly array $targets,
    ) {
    }

    public function successful(): bool
    {
        if ($this->targets === []) {
            return false;
        }
        foreach ($this->targets as $target) {
            if (!$target->successful()) {
                return false;
            }
        }

        return true;
    }

    /** @return array{scene:string,locale:string,successful:bool,targets:list<array<string,mixed>>} */
    public function toArray(): array
    {
        return [
            'scene' => $this->scene,
            'locale' => $this->locale,
            'successful' => $this->successful(),
            'targets' => array_map(static fn (NoticeTargetResult $target): array => $target->toArray(), $this->targets),
        ];
    }
}
