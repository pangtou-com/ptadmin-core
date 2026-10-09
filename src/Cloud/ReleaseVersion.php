<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;

/** 发布版本建议只比较语义版本，不以本地文件时间或平台记录 ID 排序。 */
final class ReleaseVersion
{
    public static function validate(string $version): void
    {
        if (strlen($version) > 64 || preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $version) !== 1) {
            throw new CloudException('发布版本号无效，请使用例如 1.0.0 或 1.1.0-beta.1 的版本。', 'invalid_request');
        }
        (new VersionParser())->normalize($version);
    }

    /** @return array{local:?string,patch:string,minor:string,major:string} */
    public static function suggestions(string $local, ?string $latest): array
    {
        self::validate($local);
        if ($latest !== null) {
            self::validate($latest);
        }
        $parser = new VersionParser();
        $useLocal = $latest === null || Comparator::greaterThan($parser->normalize($local), $parser->normalize($latest));
        $base = $useLocal ? $local : $latest;
        preg_match('/^(\d+)\.(\d+)\.(\d+)/', $base, $parts);
        $major = (int) $parts[1];
        $minor = (int) $parts[2];
        $patch = (int) $parts[3];
        return ['local' => $useLocal ? $local : null, 'patch' => $major.'.'.$minor.'.'.($patch + 1),
            'minor' => $major.'.'.($minor + 1).'.0', 'major' => ($major + 1).'.0.0'];
    }
}
