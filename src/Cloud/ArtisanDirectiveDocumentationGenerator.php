<?php

declare(strict_types=1);

namespace PTAdmin\Cloud;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Factory as ProcessFactory;
use PTAdmin\Cloud\Contracts\DirectiveDocumentationGeneratorInterface;
use PTAdmin\Template\Directive\DirectiveDocumentation;

final class ArtisanDirectiveDocumentationGenerator implements DirectiveDocumentationGeneratorInterface
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ProcessFactory $processes = new ProcessFactory(),
    ) {
    }

    public function generate(string $directory, string $code, string $version): ?array
    {
        $artisan = base_path('artisan');
        if (!is_file($artisan)) {
            throw new CloudException('当前宿主缺少 artisan，无法隔离生成模板指令文档。', 'package_unavailable');
        }
        $temporaryDirectory = storage_path('app/ptadmin/releases');
        $this->files->ensureDirectoryExists($temporaryDirectory, 0700);
        $output = $temporaryDirectory.'/.directive-'.bin2hex(random_bytes(12)).'.json';
        try {
            $result = $this->processes->newPendingProcess()
                ->env(['PTADMIN_ADDON_RUNTIME_ENABLED' => '0'])
                ->timeout(120)
                ->run([
                    PHP_BINARY,
                    $artisan,
                    'ptadmin:template-directives:export',
                    $directory,
                    '--code='.$code,
                    '--release-version='.$version,
                    '--output='.$output,
                    '--no-ansi',
                ]);
            if (!$result->successful()) {
                throw new CloudException(
                    '模板指令文档生成失败：'.$result->errorOutput().$result->output(),
                    'package_unavailable',
                );
            }
            if (!is_file($output)) {
                return null;
            }
            $json = $this->files->get($output);

            return DirectiveDocumentation::decode($json, $code, $version);
        } finally {
            $this->files->delete($output);
        }
    }
}
