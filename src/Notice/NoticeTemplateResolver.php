<?php

declare(strict_types=1);

namespace PTAdmin\Notice;

use Illuminate\Database\ConnectionInterface;

final class NoticeTemplateResolver
{
    private const PLACEHOLDER_PATTERN = '/\{\{\s*([^{}]+?)\s*\}\}/';

    public function __construct(private ConnectionInterface $database)
    {
    }

    /**
     * @param array<mixed> $parameters
     * @param array<mixed>|null $action
     */
    public function resolve(
        string $sceneCode,
        string $channelCode,
        string $locale = 'zh-CN',
        array $parameters = [],
        ?array $action = null,
    ): ResolvedNoticeTemplate {
        $sceneCode = strtolower(trim($sceneCode));
        $channelCode = strtolower(trim($channelCode));
        $locale = trim($locale);
        if ($sceneCode === '' || $channelCode === '' || $locale === '') {
            throw new NoticeResolutionException('通知模板解析参数不能为空。', 'notice_resolution_invalid');
        }

        $scene = $this->database->table('notice_scenes')->where('code', $sceneCode)->first();
        if (!$scene) {
            throw new NoticeResolutionException('通知场景不存在。', 'notice_scene_not_found', ['scene' => $sceneCode]);
        }
        if (!(bool) $scene->enabled) {
            throw new NoticeResolutionException('通知场景已停用。', 'notice_scene_disabled', ['scene' => $sceneCode]);
        }

        $channel = $this->database->table('notice_channels')->where('code', $channelCode)->first();
        if (!$channel) {
            throw new NoticeResolutionException('发送渠道不存在。', 'notice_channel_not_found', ['channel' => $channelCode]);
        }
        if (!(bool) $channel->enabled) {
            throw new NoticeResolutionException('发送渠道已停用。', 'notice_channel_disabled', ['channel' => $channelCode]);
        }

        $allowedChannels = $this->decodeArray($scene->channels ?? null, 'scene.channels');
        if ($allowedChannels !== [] && !in_array((string) $channel->type, $allowedChannels, true)) {
            throw new NoticeResolutionException('通知场景不允许使用该发送渠道。', 'notice_channel_not_allowed', [
                'scene' => $sceneCode,
                'channel' => $channelCode,
                'channel_type' => (string) $channel->type,
            ]);
        }

        $template = $this->database->table('notice_templates')->where([
            'scene_id' => (int) $scene->id,
            'channel' => $channelCode,
            'locale' => $locale,
        ])->first();
        if (!$template) {
            throw new NoticeResolutionException('指定语言的通知模板不存在。', 'notice_template_not_found', [
                'scene' => $sceneCode,
                'channel' => $channelCode,
                'locale' => $locale,
            ]);
        }
        if (!(bool) $template->enabled) {
            throw new NoticeResolutionException('通知模板已停用。', 'notice_template_disabled', [
                'scene' => $sceneCode,
                'channel' => $channelCode,
                'locale' => $locale,
            ]);
        }

        $editor = (string) $channel->editor;
        if ((string) $template->mode !== $editor || !in_array($editor, ['template', 'content', 'message', 'html'], true)) {
            throw new NoticeResolutionException('通知模板编辑器与发送渠道不一致。', 'notice_template_invalid', [
                'channel' => $channelCode,
                'editor' => $editor,
                'mode' => (string) $template->mode,
            ]);
        }

        $parameterDefinitions = $this->parameterDefinitions($scene->parameters ?? null);
        $validatedParameters = $this->validateParameters($parameterDefinitions, $parameters);
        $resolvedAction = $this->resolveAction($scene->supports ?? null, $action);
        $config = $this->resolveConfig(
            $editor,
            $this->decodeConfig($template->config ?? null),
            $parameterDefinitions,
            $validatedParameters,
        );

        return new ResolvedNoticeTemplate(
            scene: $sceneCode,
            channel: $channelCode,
            channelType: (string) $channel->type,
            locale: $locale,
            editor: $editor,
            config: $config,
            parameters: $validatedParameters,
            action: $resolvedAction,
        );
    }

    /**
     * @param array<string, string> $definitions
     * @param array<mixed> $parameters
     * @return array<string, string|int|float|bool>
     */
    private function validateParameters(array $definitions, array $parameters): array
    {
        if (array_is_list($parameters) && $parameters !== []) {
            throw new NoticeResolutionException('通知参数必须是以参数编码为键的对象。', 'notice_parameters_invalid');
        }
        $validated = [];
        foreach ($parameters as $key => $value) {
            if (!is_string($key) || !isset($definitions[$key])) {
                throw new NoticeResolutionException('通知参数包含场景未声明的参数。', 'notice_parameter_not_declared', [
                    'parameter' => $key,
                ]);
            }
            $type = $definitions[$key];
            if ($type === 'string' && !is_string($value)) {
                throw new NoticeResolutionException('通知参数类型与场景定义不一致。', 'notice_parameter_type_invalid', [
                    'parameter' => $key,
                    'type' => $type,
                ]);
            }
            if ($type === 'number' && !is_int($value) && !is_float($value)) {
                throw new NoticeResolutionException('通知参数类型与场景定义不一致。', 'notice_parameter_type_invalid', [
                    'parameter' => $key,
                    'type' => $type,
                ]);
            }
            if ($type === 'boolean' && !is_bool($value)) {
                throw new NoticeResolutionException('通知参数类型与场景定义不一致。', 'notice_parameter_type_invalid', [
                    'parameter' => $key,
                    'type' => $type,
                ]);
            }
            if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                throw new NoticeResolutionException('通知参数类型与场景定义不一致。', 'notice_parameter_type_invalid', [
                    'parameter' => $key,
                    'type' => $type,
                ]);
            }
            $validated[$key] = $value;
        }

        return $validated;
    }

    /** @return array<string, string> */
    private function parameterDefinitions(mixed $value): array
    {
        $definitions = [];
        foreach ($this->decodeArray($value, 'scene.parameters') as $parameter) {
            if (!is_array($parameter) || array_is_list($parameter)) {
                throw new NoticeResolutionException('通知场景参数定义无效。', 'notice_scene_invalid');
            }
            $key = $parameter['key'] ?? null;
            $type = $parameter['type'] ?? null;
            if (!is_string($key) || $key === '' || !is_string($type) || !in_array($type, ['string', 'number', 'boolean'], true)) {
                throw new NoticeResolutionException('通知场景参数定义无效。', 'notice_scene_invalid');
            }
            $definitions[$key] = $type;
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $definitions
     * @param array<string, string|int|float|bool> $parameters
     * @return array<string, mixed>
     */
    private function resolveConfig(string $editor, array $config, array $definitions, array $parameters): array
    {
        if (array_key_exists('action', $config)) {
            throw new NoticeResolutionException('通知模板不能包含具体跳转动作。', 'notice_template_invalid');
        }
        if ($editor === 'template') {
            $this->requiredString($config, 'template_code');
            $bindings = $config['bindings'] ?? [];
            if (!is_array($bindings) || (array_is_list($bindings) && $bindings !== [])) {
                throw new NoticeResolutionException('第三方模板参数映射无效。', 'notice_template_invalid');
            }
            $mapped = [];
            foreach ($bindings as $external => $parameter) {
                if (!is_string($external) || trim($external) === '' || !is_string($parameter) || !isset($definitions[$parameter])) {
                    throw new NoticeResolutionException('第三方模板参数映射无效。', 'notice_template_invalid');
                }
                if (!array_key_exists($parameter, $parameters)) {
                    throw new NoticeResolutionException('通知模板缺少必需参数。', 'notice_parameter_missing', [
                        'parameter' => $parameter,
                    ]);
                }
                $mapped[$external] = $parameters[$parameter];
            }
            unset($config['bindings']);
            $config['parameters'] = $mapped;

            return $config;
        }

        $fields = match ($editor) {
            'content' => ['content'],
            'message' => ['title', 'content'],
            'html' => ['subject', 'html'],
            default => [],
        };
        foreach ($fields as $field) {
            $this->requiredString($config, $field);
        }
        foreach (['content', 'title', 'subject', 'html', 'text'] as $field) {
            if (!array_key_exists($field, $config)) {
                continue;
            }
            if (!is_string($config[$field])) {
                throw new NoticeResolutionException('通知模板字段必须是字符串。', 'notice_template_invalid', ['field' => $field]);
            }
            $config[$field] = $this->render($config[$field], $definitions, $parameters, $field === 'html');
        }

        return $config;
    }

    /**
     * @param array<string, string> $definitions
     * @param array<string, string|int|float|bool> $parameters
     */
    private function render(string $template, array $definitions, array $parameters, bool $escapeHtml): string
    {
        return preg_replace_callback(self::PLACEHOLDER_PATTERN, function (array $matches) use ($definitions, $parameters, $escapeHtml): string {
            $parameter = trim((string) $matches[1]);
            if (!isset($definitions[$parameter])) {
                throw new NoticeResolutionException('通知模板引用了场景未声明的参数。', 'notice_template_invalid', [
                    'parameter' => $parameter,
                ]);
            }
            if (!array_key_exists($parameter, $parameters)) {
                throw new NoticeResolutionException('通知模板缺少必需参数。', 'notice_parameter_missing', [
                    'parameter' => $parameter,
                ]);
            }
            $value = $parameters[$parameter];
            $rendered = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

            return $escapeHtml ? htmlspecialchars($rendered, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $rendered;
        }, $template) ?? throw new NoticeResolutionException('通知模板渲染失败。', 'notice_template_render_failed');
    }

    /**
     * @param array<mixed>|null $action
     * @return array{type:string,target:string,label?:string}|null
     */
    private function resolveAction(mixed $supportsValue, ?array $action): ?array
    {
        if ($action === null) {
            return null;
        }
        $supports = $this->decodeArray($supportsValue, 'scene.supports');
        if (!in_array('action', $supports, true)) {
            throw new NoticeResolutionException('通知场景不支持跳转动作。', 'notice_action_not_supported');
        }
        if (array_is_list($action)
            || array_diff(array_keys($action), ['type', 'target', 'label']) !== []
            || ($action['type'] ?? null) !== 'url'
            || !is_string($action['target'] ?? null)
            || trim($action['target']) === ''
            || (isset($action['label']) && !is_string($action['label']))
        ) {
            throw new NoticeResolutionException('通知跳转动作无效。', 'notice_action_invalid');
        }

        $resolved = ['type' => 'url', 'target' => trim($action['target'])];
        if (isset($action['label']) && trim($action['label']) !== '') {
            $resolved['label'] = trim($action['label']);
        }

        return $resolved;
    }

    /** @param array<string, mixed> $config */
    private function requiredString(array $config, string $field): void
    {
        if (!isset($config[$field]) || !is_string($config[$field]) || trim($config[$field]) === '') {
            throw new NoticeResolutionException('通知模板配置缺少必填字段。', 'notice_template_invalid', ['field' => $field]);
        }
    }

    /** @return array<string, mixed> */
    private function decodeConfig(mixed $value): array
    {
        $config = $this->decodeArray($value, 'template.config');
        if (array_is_list($config) && $config !== []) {
            throw new NoticeResolutionException('通知模板配置必须是对象。', 'notice_template_invalid');
        }

        return $config;
    }

    /** @return array<mixed> */
    private function decodeArray(mixed $value, string $field): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            throw new NoticeResolutionException('通知定义包含无效 JSON。', 'notice_definition_invalid', ['field' => $field]);
        }

        return $decoded;
    }
}
