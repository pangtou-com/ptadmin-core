<?php

declare(strict_types=1);

namespace PTAdmin\Cloud\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use PTAdmin\Addon\Runtime\RuntimeRegistrar;
use PTAdmin\Addon\Runtime\TemplateDirectiveLoader;
use PTAdmin\Cloud\ReleaseVersion;
use PTAdmin\Template\Directive\DirectiveDocumentation;
use PTAdmin\Template\Directive\DirectiveRegistry;

final class DirectiveDocumentationCommand extends Command
{
    protected $signature = 'ptadmin:template-directives:export
        {source : 插件源码目录}
        {--code= : 必须与插件 manifest 一致的编码}
        {--release-version= : 写入文档的发布版本}
        {--output= : JSON 输出文件}';

    protected $description = '从插件统一注册元数据生成模板指令文档 JSON';

    public function handle(
        RuntimeRegistrar $runtime,
        TemplateDirectiveLoader $loader,
        DirectiveRegistry $registry,
        Filesystem $files,
    ): int {
        $source = realpath((string) $this->argument('source'));
        $code = (string) $this->option('code');
        $version = (string) $this->option('release-version');
        $output = (string) $this->option('output');
        if ($source === false || !is_dir($source) || is_link($source) || $output === '') {
            throw new \InvalidArgumentException('模板指令文档的源码目录或输出路径无效。');
        }
        ReleaseVersion::validate($version);
        $manifestPath = $source.'/manifest.json';
        $manifest = is_file($manifestPath)
            ? json_decode($files->get($manifestPath), true, 512, JSON_THROW_ON_ERROR)
            : null;
        if (!is_array($manifest) || ($manifest['code'] ?? null) !== $code) {
            throw new \InvalidArgumentException('插件 manifest 编码与指令文档生成目标不一致。');
        }
        $manifest['version'] = $version;
        $runtime->prepareServices($source, $manifest);
        $loader->register($source, $code, $manifest);
        $document = DirectiveDocumentation::fromRegistry($registry, $code, $version);
        if ($document === null) {
            $files->delete($output);
            $this->components->info('插件没有注册模板指令，不生成文档文件。');

            return self::SUCCESS;
        }

        $files->ensureDirectoryExists(dirname($output), 0700);
        $temporary = $output.'.tmp-'.bin2hex(random_bytes(6));
        try {
            if ($files->put($temporary, DirectiveDocumentation::encode($document), true) === false) {
                throw new \RuntimeException('模板指令文档暂存失败。');
            }
            chmod($temporary, 0600);
            if (!$files->move($temporary, $output)) {
                throw new \RuntimeException('模板指令文档写入失败。');
            }
        } finally {
            $files->delete($temporary);
        }
        $this->components->info('已生成 '.count($document['directives']).' 条模板指令文档。');

        return self::SUCCESS;
    }
}
