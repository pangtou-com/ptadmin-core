<?php

declare(strict_types=1);

namespace PTAdmin\Template\Runtime;

use Illuminate\Contracts\View\Engine;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\CompilerInterface;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\FileViewFinder;
use InvalidArgumentException;
use PTAdmin\Core\Theme\ThemeViewLoader;

/** 当前主题先执行 @pt 业务指令，再交给 Laravel Blade 编译。 */
final class ThemeTemplateEngine implements Engine
{
    public function __construct(private CompilerEngine $blade, private FileViewFinder $finder, private ThemeViewLoader $themes, private TemplateRenderer $renderer, private ?Filesystem $files = null)
    {
        $this->files ??= new Filesystem();
    }

    /** Laravel view:clear 需要清理被包装 Blade 引擎的编译状态，安装收尾也使用该命令。 */
    public function forgetCompiledOrNotExpired(): void
    {
        $this->blade->forgetCompiledOrNotExpired();
    }

    /** Laravel view:cache 通过同一引擎取得宿主 Blade 编译器。 */
    public function getCompiler(): CompilerInterface
    {
        return $this->blade->getCompiler();
    }

    public function get($path, array $data = [])
    {
        $root = $this->themes->directory();
        if ($root === null || !str_starts_with($path, rtrim($root, '/').'/')) {
            return $this->blade->get($path, $data);
        }
        try {
            $literals = [];
            $bladeLiterals = [];
            $source = strtr($this->expand($path, $root, [], $literals, $bladeLiterals), $literals);
            $source = $this->protectNativeBladeDirectives($source, $bladeLiterals);
            $data += $this->runtimeData();
            $source = strtr($this->renderer->render($source, $this->publicContext($data)), $bladeLiterals);

            // PTAdmin directives are resolved first; the resulting source is
            // then compiled by Laravel so native Blade remains available in a
            // user theme.
            $temporary = tempnam(sys_get_temp_dir(), 'ptadmin-theme-');
            if ($temporary === false) {
                throw new InvalidArgumentException('无法创建主题 Blade 编译源文件。');
            }
            $temporaryBlade = $temporary.'.blade.php';
            rename($temporary, $temporaryBlade);
            $this->files->put($temporaryBlade, $source);
            try {
                return $this->blade->get($temporaryBlade, $data);
            } finally {
                $this->files->delete($temporaryBlade);
            }
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

    /** Core 单元测试可能只有基础 Container，正式宿主则提供完整 request/config 服务。 */
    private function runtimeData(): array
    {
        $container = app();
        $request = $container->bound('request') ? $container->make('request') : null;
        $environment = 'testing';
        if ($container->bound('config')) {
            $environment = (string) $container->make('config')->get('app.env', $environment);
        } elseif ($container->bound('env')) {
            $environment = (string) $container->make('env');
        }

        return [
            'environment' => $environment,
            'current_path' => $request?->getPathInfo() ?? '/',
            'current_url' => $request?->url() ?? '',
            'query' => $request?->query() ?? [],
        ];
    }

    /** 主题中的原生控制结构必须完整交给 Blade，不能被 @pt 解析器猜测。 */
    private function protectNativeBladeDirectives(string $source, array &$literals): string
    {
        return preg_replace_callback(
            '/(?<!@)@(foreach|endforeach|forelse|empty|endforelse|while|endwhile|php|endphp|include)\b(?:\s*\([^)]*\))?/s',
            static function (array $match) use (&$literals): string {
                $placeholder = "\x1EPTBLADE".bin2hex(random_bytes(12))."\x1F";
                $directive = $match[0];
                // Migrated templates use the historical $value.path notation
                // inside Blade conditions; translate it to PHP array access.
                $directive = preg_replace_callback('/\$([A-Za-z_][A-Za-z0-9_]*)(\.[A-Za-z_][A-Za-z0-9_]*)+/', static function (array $path): string {
                    $parts = explode('.', ltrim($path[0], '$'));
                    $value = '$'.$parts[0];
                    foreach (array_slice($parts, 1) as $part) {
                        $value .= "['".$part."']";
                    }
                    return $value;
                }, $directive) ?? $directive;
                $literals[$placeholder] = $directive;
                return $placeholder;
            },
            $source,
        ) ?? $source;
    }

    private function expand(string $path, string $root, array $stack, array &$literals, array &$bladeLiterals, array $sections = []): string
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
            if ($field['blade']) {
                $bladeLiterals[$placeholder] = substr($source, $field['start'], $field['end'] - $field['start']);
            } else {
                $literals[$placeholder] = substr($source, $field['start'], $field['end'] - $field['start']);
            }
            $source = substr_replace($source, $placeholder, $field['start'], $field['end'] - $field['start']);
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
            return $this->expand($this->finder->find($parent), $root, $stack, $literals, $bladeLiterals, $sections);
        }
        $source = preg_replace_callback('/@yield\(\s*[\'\"]([A-Za-z0-9_-]+)[\'\"]\s*\)/', static fn (array $match): string => $sections[$match[1]] ?? '', $source) ?? $source;
        $source = preg_replace_callback('/@include\(\s*[\'\"]('.$logical.')[\'\"]\s*\)/', function (array $match) use ($root, $stack, &$literals, &$bladeLiterals): string {
            return $this->expand($this->finder->find($match[1]), $root, $stack, $literals, $bladeLiterals);
        }, $source) ?? $source;
        return $source;
    }
}
