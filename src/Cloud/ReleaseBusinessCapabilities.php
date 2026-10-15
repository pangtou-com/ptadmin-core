<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

/** 发布者提供的业务功能说明，仅用于版本展示和审核，不参与插件运行时注册。 */
final class ReleaseBusinessCapabilities
{
    /** @return list<array{code:string,name:string,channels:list<string>,description:string}> */
    public static function validate(mixed $declarations): array
    {
        if (!is_array($declarations) || !array_is_list($declarations) || count($declarations) > 30) {
            throw new CloudException('business_capabilities 必须是最多 30 项的业务功能列表。', 'invalid_request');
        }
        $result = [];
        $codes = [];
        foreach ($declarations as $index => $declaration) {
            $position = $index + 1;
            if (!is_array($declaration) || array_diff(array_keys($declaration), ['code', 'name', 'channels', 'description']) !== []
                || !is_string($declaration['code'] ?? null) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $declaration['code']) !== 1
                || !is_string($declaration['name'] ?? null) || trim($declaration['name']) === '' || mb_strlen($declaration['name']) > 60) {
                throw new CloudException('第 '.$position.' 项业务功能需提供有效 code 和不超过 60 字的名称 name。', 'invalid_request');
            }
            if (in_array($declaration['code'], $codes, true)) {
                throw new CloudException('业务功能编码重复：'.$declaration['code'], 'invalid_request');
            }
            $channels = $declaration['channels'] ?? [];
            $description = $declaration['description'] ?? '';
            if (!is_array($channels) || !array_is_list($channels) || count($channels) > 20
                || !is_string($description) || mb_strlen($description) > 2000) {
                throw new CloudException('业务功能 '.$declaration['code'].' 的 channels 必须为最多 20 项的渠道列表，description 不超过 2000 字。', 'invalid_request');
            }
            foreach ($channels as $channel) {
                if (!is_string($channel) || trim($channel) === '' || mb_strlen($channel) > 80) {
                    throw new CloudException('业务功能 '.$declaration['code'].' 的渠道名称必须为 1 至 80 字。', 'invalid_request');
                }
            }
            $codes[] = $declaration['code'];
            $result[] = ['code' => $declaration['code'], 'name' => $declaration['name'], 'channels' => $channels, 'description' => $description];
        }

        return $result;
    }
}
