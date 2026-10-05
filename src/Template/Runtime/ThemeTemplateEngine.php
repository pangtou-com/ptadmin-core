<?php

declare(strict_types=1);

namespace PTAdmin\Template\Runtime;

use Illuminate\Contracts\View\Engine;
use Illuminate\View\FileViewFinder;
use InvalidArgumentException;
use PTAdmin\Core\Theme\ThemeViewLoader;

/** 仅当前主题使用受限模板解释器；宿主命名空间视图保留原 Blade 引擎。 */
final class ThemeTemplateEngine implements Engine
{
    public function __construct(private Engine $blade, private FileViewFinder $finder, private ThemeViewLoader $themes, private TemplateRenderer $renderer)
    {
    }

    public function get($path, array $data = [])
    {
        $root = $this->themes->directory();
        if ($root === null || !str_starts_with($path, rtrim($root, '/').'/')) {
            return $this->blade->get($path, $data);
        }
        try {
            $literals = [];
            $source = strtr($this->expand($path, $root, [], $literals), $literals);
            $data += ['environment' => app()->environment(), 'current_path' => request()->getPathInfo(), 'current_url' => request()->url(), 'query' => request()->query()];
            return $this->renderer->render($source, $this->publicContext($data));
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('主题模板 '.substr($path, strlen(rtrim($root, '/')) + 1).'：'.$exception->getMessage(), 0, $exception);
        }
    }

    /** Laravel 共享的 app、__env 等服务对象不能成为主题可遍历的公开数据。 */
    private function publicContext(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (str_starts_with((string) $key, '__')) {
                continue;
            }
            if (is_array($value)) {
                $result[$key] = $this->publicContext($value);
            } elseif (is_scalar($value) || $value === null || $value instanceof SafeHtml || $value instanceof \DateTimeInterface) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private function expand(string $path, string $root, array $stack, array &$literals, array $sections = []): string
    {
        $real = realpath($path);
        $base = realpath($root);
        if ($real === false || $base === false || !str_starts_with($real, $base.'/') || is_link($path)) {
            throw new InvalidArgumentException('模板引用必须位于当前主题内。');
        }
        if (isset($stack[$real]) || count($stack) >= 20) {
            throw new InvalidArgumentException('模板包含或布局存在循环，或超过 20 层。');
        }
        $stack[$real] = true;
        $source = file_get_contents($real);
        if ($source === false) {
            throw new InvalidArgumentException('模板无法读取。');
        }
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? $source;
        // 字段参数中的 @include/@section 等只作为文字，不能参与布局展开。
        foreach (array_reverse((new \PTAdmin\Template\Parsing\FieldOutputParser())->tokens($source)) as $field) {
            $placeholder = "\x1EPTFIELD".bin2hex(random_bytes(12))."\x1F";
            $literals[$placeholder] = substr($source, $field['start'], $field['end'] - $field['start']);
            $source = substr_replace($source, $placeholder, $field['start'], $field['end'] - $field['start']);
        }
        if (preg_match('/<\?|\{!!|\{\{/', $source, $bad, PREG_OFFSET_CAPTURE)) {
            throw new InvalidArgumentException('第 '.(substr_count(substr($source, 0, $bad[0][1]), "\n") + 1).' 行包含不支持的 PHP/Blade 表达式，请使用受控字段输出。');
        }
        $logical = '[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*';
        $parent = null;
        $source = preg_replace_callback('/@extends\(\s*[\'\"]('.$logical.')[\'\"]\s*\)/', function (array $match) use (&$parent): string {
            if ($parent !== null) {
                throw new InvalidArgumentException('模板只能声明一个布局。');
            }
            $parent = $match[1];
            return '';
        }, $source) ?? $source;
        $source = preg_replace_callback('/@section\(\s*[\'\"]([A-Za-z0-9_-]+)[\'\"]\s*\)(.*?)@endsection/s', static function (array $match) use (&$sections): string {
            $sections[$match[1]] ??= $match[2];
            return '';
        }, $source) ?? $source;
        if ($parent !== null) {
            return $this->expand($this->finder->find($parent), $root, $stack, $literals, $sections);
        }
        $source = preg_replace_callback('/@yield\(\s*[\'\"]([A-Za-z0-9_-]+)[\'\"]\s*\)/', static fn (array $match): string => $sections[$match[1]] ?? '', $source) ?? $source;
        $source = preg_replace_callback('/@include\(\s*[\'\"]('.$logical.')[\'\"]\s*\)/', function (array $match) use ($root, $stack, &$literals): string {
            return $this->expand($this->finder->find($match[1]), $root, $stack, $literals);
        }, $source) ?? $source;
        if (preg_match_all('/(?<![@A-Za-z0-9_])@(?!pt:|if\b|elseif\b|else\b|endif\b)([A-Za-z_][A-Za-z0-9_]*)\s*(?:\(|\b)/', $source, $badMatches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($badMatches as $bad) {
                // CSS @media 等静态规则不是模板语法。
                if (!in_array($bad[1][0], ['media', 'supports', 'keyframes', 'font', 'import', 'charset', 'layer', 'container', 'page', 'property'], true)) {
                    throw new InvalidArgumentException('第 '.(substr_count(substr($source, 0, $bad[0][1]), "\n") + 1).' 行包含不支持的模板语法 @'.$bad[1][0]);
                }
            }
        }
        return $source;
    }
}
