<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

use PTAdmin\Notice\Contracts\NoticeChannelRoutingHookInterface;

final class NoticeChannelRouter
{
    /** @var list<array{hook:NoticeChannelRoutingHookInterface,priority:int,order:int}> */
    private array $hooks = [];

    private int $registrationOrder = 0;

    public function register(NoticeChannelRoutingHookInterface $hook, int $priority = 0): void
    {
        $this->hooks[] = [
            'hook' => $hook,
            'priority' => $priority,
            'order' => $this->registrationOrder++,
        ];
    }

    /** @param list<string> $candidates */
    public function select(NoticeRoutingContext $context, array $candidates): string
    {
        if ($candidates === []) {
            throw new NoticeSendingException('没有可用的发送渠道。', 'notice_channel_unavailable', [
                'scene' => $context->scene,
                'channel_type' => $context->channelType,
                'locale' => $context->locale,
            ]);
        }
        $hooks = $this->hooks;
        usort($hooks, static function (array $left, array $right): int {
            return ($right['priority'] <=> $left['priority']) ?: ($left['order'] <=> $right['order']);
        });
        foreach ($hooks as $registered) {
            $selected = $registered['hook']->select($context, $candidates);
            if ($selected === null) {
                continue;
            }
            $selected = strtolower(trim($selected));
            if (!in_array($selected, $candidates, true)) {
                throw new NoticeSendingException('渠道路由 Hook 返回了候选列表之外的渠道。', 'notice_channel_route_invalid', [
                    'selected' => $selected,
                    'candidates' => $candidates,
                ]);
            }

            return $selected;
        }

        return $candidates[0];
    }
}
