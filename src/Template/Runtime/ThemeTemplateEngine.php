<?php

declare(strict_types=1);

namespace PTAdmin\Template\Runtime;

use Illuminate\Contracts\View\Engine;
use Illuminate\View\Compilers\CompilerInterface;
use Illuminate\View\Engines\CompilerEngine;
use InvalidArgumentException;
use PTAdmin\Core\Theme\ThemeViewLoader;

/** 主题业务调用与原生 Blade 在同一编译结果中按源码顺序执行。 */
final class ThemeTemplateEngine implements Engine
{
    /** @var array<string, true> */
    private array $rendering = [];
    private ?TemplateRenderer $runtime = null;

    public function __construct(private readonly CompilerEngine $blade, private readonly ThemeViewLoader $themes, private readonly TemplateRenderer $renderer)
    {
        $compiler = $this->blade->getCompiler();
        if (!$compiler instanceof \Illuminate\View\Compilers\BladeCompiler) {
            throw new InvalidArgumentException('主题引擎需要 Laravel Blade 编译器。');
        }
        $compiler->precompiler(function (string $source) use ($compiler): string {
            $root = $this->themes->directory();
            if ($root === null || !str_starts_with($compiler->getPath(), rtrim($root, '/').'/')) {
                return $source;
            }
            return (new ThemeBladeCompiler($this->renderer))->compile($source);
        });
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
        $real = realpath($path);
        $base = realpath($root);
        if ($real === false || $base === false || !str_starts_with($real, $base.'/') || is_link($path)) {
            throw new InvalidArgumentException('模板引用必须位于当前主题内。');
        }
        if (isset($this->rendering[$real]) || count($this->rendering) >= 20) {
            throw new InvalidArgumentException('模板包含或布局存在循环，或超过 20 层。');
        }
        if ($this->rendering === []) {
            $this->runtime = clone $this->renderer;
        }
        $this->rendering[$real] = true;
        $data += $this->runtimeData();
        $data['__ptadmin'] = $this->runtime;
        try {
            return $this->blade->get($path, $data);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('主题模板 '.substr($path, strlen(rtrim($root, '/')) + 1).'：'.$exception->getMessage(), 0, $exception);
        } finally {
            unset($this->rendering[$real]);
            if ($this->rendering === []) {
                $this->runtime = null;
            }
        }
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
}
