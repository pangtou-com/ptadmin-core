<?php

declare(strict_types=1);

namespace PTAdmin\Core\Updates;

/** 保留项目说明；content-hash 使用 Composer Locker 的公开算法，不解析依赖。 */
final class ComposerProjectMetadata
{
    public static function preserve(string $current, string $stage): void
    {
        $root = UpdateFiles::read($current.'/composer.json');
        $target = UpdateFiles::read($stage.'/composer.json');
        foreach (['name', 'description', 'keywords', 'homepage', 'license', 'authors', 'support', 'funding', '$schema'] as $key) {
            if (array_key_exists($key, $root)) {
                $target[$key] = $root[$key];
            } else {
                unset($target[$key]);
            }
        }
        $lock = UpdateFiles::read($stage.'/composer.lock');
        $relevant = array_intersect_key($target, array_flip(['name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra']));
        if (isset($target['config']['platform'])) {
            $relevant['config']['platform'] = $target['config']['platform'];
        }
        ksort($relevant);
        // Composer Locker 使用 JsonFile::encode($content, 0)，保留默认的斜线与 Unicode 转义。
        $lock['content-hash'] = md5(json_encode($relevant, JSON_THROW_ON_ERROR));
        UpdateFiles::write($stage.'/composer.json', $target);
        UpdateFiles::write($stage.'/composer.lock', $lock);
        chmod($stage.'/composer.json', fileperms($current.'/composer.json') & 0777);
        chmod($stage.'/composer.lock', fileperms($current.'/composer.lock') & 0777);
    }
}
