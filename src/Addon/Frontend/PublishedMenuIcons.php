<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Frontend;

use DOMDocument;
use DOMElement;
use DOMProcessingInstruction;
use JsonException;
use PTAdmin\Addon\Exception\AddonException;

/** 发布时验证 SVG，Session 仅消费发布器生成的图标索引。 */
final class PublishedMenuIcons
{
    private const SCHEMA = 'ptadmin.menu-icons/v1';
    private const INDEX = '.ptadmin-icons.json';
    private const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
    private const ELEMENTS = ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'title', 'desc'];
    private const ATTRIBUTES = [
        'viewBox', 'width', 'height', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'd', 'points', 'transform', 'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width',
        'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset',
        'stroke-opacity', 'opacity', 'preserveAspectRatio',
    ];

    /** 在已复制完成的 staging 内执行；任何无效图标均阻止替换当前发布目录。 */
    public function prepare(string $directory, string $code): void
    {
        $descriptorPath = $directory.'/.vite/ptadmin-icons.json';
        $icons = [];
        if (is_file($descriptorPath)) {
            $descriptor = $this->readJson($descriptorPath, $code);
            $declared = $descriptor['icons'] ?? null;
            if (($descriptor['schema'] ?? null) !== self::SCHEMA || !is_array($declared)
                || ($declared !== [] && array_is_list($declared)) || count($declared) > 128) {
                $this->invalid($code, '构建描述格式无效或超过 128 个图标。');
            }
            foreach ($declared as $name => $path) {
                if (!is_string($name) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name) !== 1 || !$this->safePath($path)) {
                    $this->invalid($code, '图标名称或相对 SVG 路径无效。');
                }
                $file = realpath($directory.'/'.$path);
                $root = realpath($directory);
                if ($file === false || $root === false || !str_starts_with($file, $root.DIRECTORY_SEPARATOR)
                    || !is_file($file) || is_link($directory.'/'.$path)) {
                    $this->invalid($code, sprintf('图标 %s 文件不存在或超出发布目录。', $name));
                }
                $this->validateSvg($file, $code, $name);
                $icons[$name] = ['path' => $path, 'sha256' => hash_file('sha256', $file)];
            }
        }
        // 总是重建索引，禁止插件伪造已验证索引，并清除更新后不再声明的图标。
        if (file_put_contents($directory.'/'.self::INDEX, json_encode([
            'schema' => self::SCHEMA, 'code' => $code, 'icons' => $icons,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) {
            $this->invalid($code, '无法写入发布图标索引。');
        }
    }

    /** @return array<string, string> 完整命名空间到公开 URL 的映射。 */
    public function resolve(string $directory, string $code, string $urlPrefix): array
    {
        $path = $directory.'/'.self::INDEX;
        if (!is_file($path)) {
            return [];
        }
        $index = $this->readJson($path, $code);
        if (($index['schema'] ?? null) !== self::SCHEMA || ($index['code'] ?? null) !== $code || !is_array($index['icons'] ?? null)) {
            $this->invalid($code, '发布图标索引无效。');
        }
        $resolved = [];
        foreach ($index['icons'] as $name => $icon) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name) !== 1
                || !is_array($icon) || !$this->safePath($icon['path'] ?? null)
                || !is_string($icon['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $icon['sha256']) !== 1) {
                $this->invalid($code, '发布图标索引条目无效。');
            }
            $resolved[$code.':'.$name] = $urlPrefix.'/'.$icon['path'].'?v='.$icon['sha256'];
        }
        return $resolved;
    }

    private function safePath(mixed $path): bool
    {
        return is_string($path) && preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.\/-]*\.svg$/D', $path) === 1
            && !array_intersect(['.', '..', ''], explode('/', $path));
    }

    private function validateSvg(string $path, string $code, string $name): void
    {
        if (filesize($path) > 65536) {
            $this->invalid($code, sprintf('图标 %s 超过 64 KiB。', $name));
        }
        $svg = file_get_contents($path);
        if ($svg === false || strlen($svg) > 65536 || stripos($svg, '<!DOCTYPE') !== false || stripos($svg, '<!ENTITY') !== false) {
            $this->invalid($code, sprintf('图标 %s 超过 64 KiB 或包含 DTD/实体声明。', $name));
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $valid = $document->loadXML($svg, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->documentElement;
        if (!$valid || $document->doctype !== null || !$root instanceof DOMElement || $root->tagName !== 'svg' || $root->namespaceURI !== self::SVG_NAMESPACE
            || !$this->validViewBox($root->getAttribute('viewBox'))) {
            $this->invalid($code, sprintf('图标 %s 必须是带 SVG 命名空间和 viewBox 的有效 SVG。', $name));
        }
        foreach ($document->childNodes as $child) {
            if ($child instanceof DOMProcessingInstruction) {
                $this->invalid($code, sprintf('图标 %s 不允许 XML 处理指令。', $name));
            }
        }
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element->namespaceURI !== self::SVG_NAMESPACE || !in_array($element->tagName, self::ELEMENTS, true)) {
                $this->invalid($code, sprintf('图标 %s 包含不支持的元素 %s。', $name, $element->tagName));
            }
            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMProcessingInstruction) {
                    $this->invalid($code, sprintf('图标 %s 不允许 XML 处理指令。', $name));
                }
            }
            foreach ($element->attributes as $attribute) {
                if ($attribute->namespaceURI !== null || !in_array($attribute->name, self::ATTRIBUTES, true)
                    || preg_match('/url\s*\(/i', $attribute->value) === 1) {
                    $this->invalid($code, sprintf('图标 %s 包含不支持的属性 %s。', $name, $attribute->name));
                }
            }
        }
    }

    private function validViewBox(string $value): bool
    {
        $coordinates = preg_split('/[\s,]+/', trim($value));
        if ($coordinates === false || count($coordinates) !== 4) {
            return false;
        }
        foreach ($coordinates as $coordinate) {
            if (!is_numeric($coordinate) || !is_finite((float) $coordinate)) {
                return false;
            }
        }
        return (float) $coordinates[2] > 0 && (float) $coordinates[3] > 0;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path, string $code): array
    {
        try {
            $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid($code, '图标描述不是有效 JSON。');
        }
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid($code, '图标描述必须为对象。');
        }
        return $value;
    }

    private function invalid(string $code, string $message): never
    {
        throw new AddonException(sprintf('插件 %s 菜单图标：%s', $code, $message), 'frontend_icons_invalid');
    }
}
