<?php

declare(strict_types=1);

namespace PTAdmin\Cloud\Console;

use Illuminate\Console\Command;
use PTAdmin\Cloud\ReleasePublisher;
use PTAdmin\Cloud\ReleaseVersion;
use PTAdmin\Cloud\CloudException;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class PublishCommand extends Command
{
    protected $signature = 'ptadmin:publish
        {type : addon 或 template}
        {source : 插件编码、模板编码或本地源码目录}
        {--versions= : 指定本次发布版本，不修改源码}
        {--bump= : 自动递增 patch、minor 或 major，与 --versions 互斥}
        {--changelog= : 版本更新说明}
        {--skip-build : 显式使用已有构建产物，仍校验双包}
        {--dry-run : 仅本地校验和打包，不提交平台}';

    protected $description = '校验、打包并发布插件或模板版本到云市场';

    public function handle(ReleasePublisher $publisher): int
    {
        try {
            $version = $this->option('versions');
            $bump = $this->option('bump');
            $dryRun = (bool) $this->option('dry-run');
            $interactive = $this->input->isInteractive();
            if ($version !== null && $bump !== null) {
                throw new CloudException('--versions 与 --bump 不能同时使用。', 'invalid_request');
            }
            if ($version !== null) {
                ReleaseVersion::validate($version);
            }
            if ($bump !== null && !in_array($bump, ['patch', 'minor', 'major'], true)) {
                throw new CloudException('--bump 只支持 patch、minor 或 major。', 'invalid_request');
            }
            if (!$interactive && !$dryRun && $version === null && $bump === null) {
                throw new CloudException('非交互发布请指定 --versions 或 --bump。', 'invalid_request');
            }
            $prepared = $publisher->prepare($this->argument('type'), $this->argument('source'), $dryRun,
                $interactive ? fn (array $directories): string => $this->chooseSource($directories) : null);
            $this->line('本地来源：'.OutputFormatter::escape($prepared['directory']));
            $this->line('本地版本：'.$prepared['local_version']);
            $this->line('平台最新版本：'.($dryRun ? '未查询（本地检查）' : ($prepared['latest_version'] ?? '尚无版本')));
            $suggestions = ReleaseVersion::suggestions($prepared['local_version'], $prepared['latest_version']);
            if ($version === null) {
                $version = $bump !== null ? $suggestions[$bump]
                    : ($interactive ? $this->chooseVersion($suggestions) : $prepared['local_version']);
            }
            ReleaseVersion::validate($version);
            $changelog = $this->option('changelog');
            if ($changelog === null) {
                $changelog = $interactive ? ($this->ask('请输入本次版本更新说明（可留空）') ?? '') : '';
            }
            $this->line('商品：'.OutputFormatter::escape($prepared['product']['title'] ?? $prepared['code']).'（'.$prepared['code'].'）');
            $this->line('发布版本：'.$version);
            $this->line('更新说明：'.($changelog === '' ? '未填写' : OutputFormatter::escape($changelog)));
            $this->line('交付文件：安装包和源码包；'.($this->option('skip-build') ? '使用已有构建产物。' : '在临时副本执行项目构建。'));
            if ($interactive && !$this->confirm($dryRun ? '确认执行本地发布检查？' : '确认提交此版本草稿？', false)) {
                $this->info('已取消发布。');
                return self::SUCCESS;
            }
            $result = $publisher->publish($prepared, $version, $changelog, (bool) $this->option('skip-build'), fn (string $message) => $this->line(OutputFormatter::escape($message)));
            $this->line('版本：'.$result['code'].'@'.$result['version'].'；文件：'.$result['files'].'；ZIP：'.$result['bytes'].' 字节。');
            $this->line('源码包：'.$result['source_files'].' 个文件；ZIP：'.$result['source_bytes'].' 字节。');
            if ($result['dry_run']) {
                foreach (['paths' => '安装包', 'source_paths' => '源码包', 'excluded' => '默认排除'] as $field => $label) {
                    $this->line($label.'：');
                    foreach ($result[$field] as $path) {
                        $this->line('  '.OutputFormatter::escape($path));
                    }
                }
                $this->info('本地发布检查通过，未提交平台。');
            } else {
                $this->info('版本草稿已提交，商品 ID：'.$result['product_id'].'，版本 ID：'.$result['release_id'].'。');
            }
            return self::SUCCESS;
        } catch (\RuntimeException | \JsonException $exception) {
            $this->error('版本发布失败：'.$exception->getMessage());
            return self::FAILURE;
        }
    }

    /** @param list<string> $directories */
    private function chooseSource(array $directories): string
    {
        $choices = [];
        foreach ($directories as $directory) {
            $choices[basename($directory)] = $directory;
        }
        return $choices[$this->choice('请选择本地模板来源（本次发布版本将在下一步指定）', array_keys($choices))];
    }

    /** @param array{local:?string,patch:string,minor:string,major:string} $suggestions */
    private function chooseVersion(array $suggestions): string
    {
        $choices = [];
        if ($suggestions['local'] !== null) {
            $choices['使用本地版本 '.$suggestions['local']] = $suggestions['local'];
        }
        $choices['修订版本 '.$suggestions['patch'].'（修复问题）'] = $suggestions['patch'];
        $choices['次版本 '.$suggestions['minor'].'（新增功能）'] = $suggestions['minor'];
        $choices['主版本 '.$suggestions['major'].'（不兼容变更）'] = $suggestions['major'];
        $choices['自定义版本'] = null;
        $selected = $choices[$this->choice('请选择发布版本', array_keys($choices), 0)];
        if ($selected !== null) {
            return $selected;
        }
        return $this->output->ask('请输入自定义版本', null, static function (?string $answer): string {
            $version = $answer ?? '';
            ReleaseVersion::validate($version);
            return $version;
        });
    }
}
