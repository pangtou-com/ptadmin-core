<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

final class AddonBuiltinTemplateDirectives
{
    public function register(AddonTemplateDirectiveRegistration $registry): void
    {
        $registry->define('seo')->output([$this, 'seo'])->alias('seo')->safeHtml()
            ->description('输出当前页面 SEO 头部。')
            ->parameter('only', type: 'array', description: '要输出的 SEO 字段。')
            ->parameter('data', type: 'object', description: '显式 SEO 数据。')
            ->commit();
        foreach (['img', 'images', 'file', 'files'] as $name) {
            $builder = $registry->define($name)->output([$this, $name])->alias($name)->safeHtml()
                ->description('输出媒体资源。')
                ->parameter('value', type: 'mixed', description: '媒体对象、地址或媒体集合。')
                ->parameter('key', type: 'string', description: '地址字段名。', default: 'key')
                ->parameter('label', type: 'string', description: '显示文本字段名。', default: 'label')
                ->parameter('attrs', type: 'object', description: '附加 HTML 属性。')
                ->parameter('limit', type: 'integer', description: '最多输出的媒体数量。', min: 1)
                ->parameter('empty', type: 'string', description: '没有可输出媒体时的文本。')
                ->parameter('separator', type: 'string', description: '批量媒体之间的分隔文本。', default: '');
            $builder->commit();
        }
        $registry->define('dump')->output([$this, 'dump'])->alias('dump')->safeHtml()
            ->description('开发环境输出调试信息。')
            ->parameter('context', type: 'object', description: '显式调试上下文。')
            ->parameter('label', type: 'string', description: '调试标签。')
            ->parameter('once', type: 'boolean', description: '是否只输出一次。')
            ->commit();
    }

    public function seo(array $arguments, array $context = []): string
    {
        $data = $arguments['data'] ?? ($context['seo'] ?? []);
        if (!is_array($data)) $data = [];
        $only = $arguments['only'] ?? null;
        $keys = is_array($only) ? $only : ['title', 'description', 'keywords', 'canonical'];
        $html = '';
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (!is_scalar($value) || $value === '') continue;
            $escaped = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= match ($key) { 'title' => '<title>'.$escaped.'</title>', 'description', 'keywords' => '<meta name="'.$key.'" content="'.$escaped.'">', 'canonical' => '<link rel="canonical" href="'.$escaped.'">', default => '' };
        }
        return $html;
    }

    public function dump(array $arguments, array $context = []): string
    {
        if (($context['environment'] ?? 'production') !== 'local' && ($context['debug'] ?? false) !== true) return '';
        $label = (string) ($arguments['label'] ?? '');
        $once = ($arguments['once'] ?? false) === true;
        if ($once) {
            $state =& $context['__ptadmin_render_state'];
            if (!is_array($state)) $state = [];
            $key = 'dump:'.($label !== '' ? $label : 'default');
            if (($state[$key] ?? false) === true) return '';
            $state[$key] = true;
        }
        $value = $arguments['context'] ?? $context;
        if (is_array($value)) {
            unset($value['__ptadmin_render_state'], $value['diagnostic']);
        }
        $value = $this->sanitizeDumpValue($value);
        $prefix = $label === '' ? '' : "<strong>".htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</strong>\n";

        return '<pre>'.$prefix.htmlspecialchars(print_r($value, true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre>';
    }

    public function img(array $arguments, array $context = []): string { return $this->media($arguments, $context, false, true); }
    public function images(array $arguments, array $context = []): string { return $this->media($arguments, $context, true, true); }
    public function file(array $arguments, array $context = []): string { return $this->media($arguments, $context, false, false); }
    public function files(array $arguments, array $context = []): string { return $this->media($arguments, $context, true, false); }

    private function media(array $arguments, array $context, bool $many, bool $image): string
    {
        $value = array_key_exists('value', $arguments)
            ? $arguments['value']
            : $this->resolveMediaContext($context);
        if ($value === null) return $this->escapeHtml((string) ($arguments['empty'] ?? ''));
        $items = $many ? (is_array($value) ? $value : [$value]) : [$value];
        if (isset($arguments['limit']) && is_int($arguments['limit'])) $items = array_slice($items, 0, $arguments['limit']);
        $key = (string) ($arguments['key'] ?? 'key'); $label = (string) ($arguments['label'] ?? 'label'); $attrs = is_array($arguments['attrs'] ?? null) ? $arguments['attrs'] : [];
        $output = [];
        foreach ($items as $item) {
            $item = is_array($item) ? $item : ['key' => $item]; $url = $item[$key] ?? null; if (!is_string($url) || !preg_match('#^(?:https?://|/|\.{1,2}/)#i', $url) || preg_match('/[\x00-\x20"\']/', $url)) continue;
            $safeAttrs = ''; foreach ($attrs as $attribute => $attributeValue) { if (!is_string($attribute) || preg_match('/^on/i', $attribute)) continue; $safeAttrs .= ' '.htmlspecialchars($attribute, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'="'.htmlspecialchars((string) $attributeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"'; }
            $text = htmlspecialchars((string) ($item[$label] ?? $url), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); $escapedUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $output[] = $image ? '<img src="'.$escapedUrl.'" alt="'.$text.'"'.$safeAttrs.'>' : '<a href="'.$escapedUrl.'"'.$safeAttrs.'>'.$text.'</a>';
        }
        if ($output === []) return $this->escapeHtml((string) ($arguments['empty'] ?? ''));

        return implode($this->escapeHtml((string) ($arguments['separator'] ?? '')), $output);
    }

    private function sanitizeDumpValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/(?:token|password|secret|cookie)/i', $key)) {
            return '[REDACTED]';
        }
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $entryKey => $entryValue) {
                $sanitized[$entryKey] = $this->sanitizeDumpValue($entryValue, is_string($entryKey) ? $entryKey : null);
            }

            return $sanitized;
        }
        if (is_object($value)) {
            $sanitized = [];
            foreach (get_object_vars($value) as $entryKey => $entryValue) {
                $sanitized[$entryKey] = $this->sanitizeDumpValue($entryValue, $entryKey);
            }

            return $sanitized;
        }

        return $value;
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function resolveMediaContext(array $context): mixed
    {
        if (array_key_exists('current_item', $context)) return $context['current_item'];
        if (array_key_exists('media', $context)) return $context['media'];

        $schema = $context['schema_media'] ?? null;
        if (!is_array($schema)) return null;
        if (array_key_exists('unique', $schema)) return $schema['unique'];
        if (array_key_exists('value', $schema)) return $schema['value'];
        if (array_key_exists('collection', $schema)) return $schema['collection'];
        if (!array_key_exists('candidates', $schema)) return null;

        $candidates = is_array($schema['candidates']) ? array_values($schema['candidates']) : [];
        if (count($candidates) === 1) return $candidates[0];
        if (count($candidates) > 1 && is_callable($context['diagnostic'] ?? null)) {
            ($context['diagnostic'])('media_ambiguous', ['count' => count($candidates)]);
        }

        return null;
    }
}
