<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

use Illuminate\Database\ConnectionInterface;

final class NoticeTemplateValidator
{
    public function __construct(private ConnectionInterface $database)
    {
    }

    /** @param array<string, mixed> $prepared @return array<string, mixed> */
    public function validate(array $prepared, ?int $recordId = null): array
    {
        $definition = $prepared;
        if ($recordId !== null) {
            $stored = $this->database->table('notice_templates')->where('id', $recordId)->first();
            if (!$stored) {
                throw new NoticeTemplateException('通知模板不存在。', ['id' => $recordId]);
            }
            $definition = array_replace(get_object_vars($stored), $prepared);
        }

        $sceneId = (int) ($definition['scene_id'] ?? 0);
        $scene = $this->database->table('notice_scenes')->where('id', $sceneId)->where('enabled', true)->first();
        if (!$scene) {
            throw new NoticeTemplateException('通知模板所属场景不存在。', ['field' => 'scene_id']);
        }
        $channelCode = trim((string) ($definition['channel'] ?? ''));
        $channel = $this->database->table('notice_channels')->where('code', $channelCode)->where('enabled', true)->first();
        if (!$channel) {
            throw new NoticeTemplateException('通知模板发送渠道未注册。', ['field' => 'channel']);
        }
        $allowedChannels = $this->decodeJson($scene->channels ?? null);
        if ($allowedChannels !== [] && !in_array((string) $channel->type, $allowedChannels, true)) {
            throw new NoticeTemplateException('通知场景不支持该发送渠道。', ['field' => 'channel']);
        }
        $locale = (string) ($definition['locale'] ?? '');
        $duplicate = $this->database->table('notice_templates')
            ->where(['scene_id' => $sceneId, 'channel' => $channelCode, 'locale' => $locale]);
        if ($recordId !== null) {
            $duplicate->where('id', '!=', $recordId);
        }
        if ($duplicate->exists()) {
            throw new NoticeTemplateException('该场景、渠道和语言区域的模板已经存在。', ['field' => 'locale']);
        }

        $prepared['mode'] = (string) $channel->editor;
        $config = $this->decodeConfig($definition['config'] ?? []);
        if (array_key_exists('action', $config)) {
            throw new NoticeTemplateException('通知模板不能保存具体跳转动作。', ['field' => 'config.action']);
        }
        $required = match ((string) $channel->editor) {
            'template' => ['template_code'],
            'message' => ['title', 'content'],
            'html' => ['subject', 'html'],
            default => ['content'],
        };
        foreach ($required as $field) {
            if (!array_key_exists($field, $config) || !is_string($config[$field]) || trim($config[$field]) === '') {
                throw new NoticeTemplateException('通知模板配置缺少必填字段。', ['field' => 'config.'.$field]);
            }
        }

        $parameters = [];
        foreach ($this->decodeJson($scene->parameters ?? null) as $parameter) {
            if (is_array($parameter) && isset($parameter['key'])) {
                $parameters[(string) $parameter['key']] = true;
            }
        }
        if ((string) $channel->editor === 'template') {
            $bindings = $config['bindings'] ?? [];
            if (!is_array($bindings) || array_is_list($bindings)) {
                throw new NoticeTemplateException('第三方模板参数映射必须是对象。', ['field' => 'config.bindings']);
            }
            foreach ($bindings as $external => $target) {
                if (!is_string($external) || trim($external) === '' || !is_string($target) || !isset($parameters[$target])) {
                    throw new NoticeTemplateException('第三方模板参数映射引用了未声明的场景参数。', ['field' => 'config.bindings']);
                }
            }
        }
        foreach (['content', 'title', 'subject', 'html', 'text'] as $field) {
            if (!isset($config[$field]) || !is_string($config[$field])) {
                continue;
            }
            preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/', $config[$field], $matches);
            foreach ($matches[1] as $parameter) {
                $parameter = trim($parameter);
                if (!isset($parameters[$parameter])) {
                    throw new NoticeTemplateException('通知模板引用了未声明的场景参数。', [
                        'field' => 'config.'.$field,
                        'parameter' => $parameter,
                    ]);
                }
            }
        }

        return $prepared;
    }

    /** @return array<string, mixed> */
    private function decodeConfig(mixed $value): array
    {
        $config = $this->decodeJson($value);
        if (array_is_list($config) && $config !== []) {
            throw new NoticeTemplateException('通知模板配置必须是对象。', ['field' => 'config']);
        }

        return $config;
    }

    /** @return array<mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            throw new NoticeTemplateException('通知模板配置不是有效的 JSON 对象。', ['field' => 'config']);
        }

        return $decoded;
    }
}
