<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Frontend;

use JsonException;
use PTAdmin\Addon\Exception\AddonException;

/** 应用与插件共同使用已发布构建的样式清单。 */
final class PublishedBuildStyles
{
    /** @return list<string> */
    public function resolve(string $directory, string $entryPath, string $urlPrefix): array
    {
        $root = realpath($directory);
        $path = $root.'/.vite/sparq-root.json';
        if (!is_file($path)) {
            return [];
        }
        $realPath = realpath($path);
        if ($root === false || $realPath === false || !str_starts_with($realPath, $root.DIRECTORY_SEPARATOR)) {
            throw new AddonException('前端模块构建描述不在发布目录内。', 'frontend_build_invalid');
        }
        try {
            $build = json_decode((string) file_get_contents($realPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AddonException('前端模块构建描述不是有效 JSON。', 'frontend_build_invalid');
        }
        if (!is_array($build) || ($build['schema'] ?? null) !== 'sparq.frontend-build/v1'
            || ($build['entry'] ?? null) !== $entryPath || !is_array($build['styles'] ?? null) || !array_is_list($build['styles'])) {
            throw new AddonException('前端模块构建描述的入口或样式清单无效。', 'frontend_build_invalid');
        }
        $urls = [];
        foreach ($build['styles'] as $style) {
            if (!is_string($style) || preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.\/-]*\.css$/', $style) !== 1
                || in_array('..', explode('/', $style), true) || in_array('.', explode('/', $style), true)
                || in_array('', explode('/', $style), true)) {
                throw new AddonException('前端模块 CSS 路径无效。', 'frontend_style_invalid');
            }
            $file = realpath($root.'/'.$style);
            if ($file === false || !str_starts_with($file, $root.DIRECTORY_SEPARATOR) || !is_file($file)) {
                throw new AddonException('前端模块 CSS 未发布或超出发布目录。', 'frontend_style_unavailable', ['style' => $style]);
            }
            $urls[] = $urlPrefix.'/'.implode('/', array_map('rawurlencode', explode('/', $style)))
                .'?v='.hash_file('sha256', $file);
        }
        return array_values(array_unique($urls));
    }
}
