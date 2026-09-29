<?php

declare(strict_types=1);

namespace PTAdmin\Template\Builtin;

use PTAdmin\Core\Config\ConfigService;
use PTAdmin\Schema\Support\FieldProtection;
use PTAdmin\Template\Directive\DirectiveRegistration;

final class PublicConfigDirectiveProvider
{
    public function __construct(private ConfigService $configs)
    {
    }

    public function register(DirectiveRegistration $registry): void
    {
        $registry->define('config')->output([$this, 'value'])->alias('config')
            ->description('读取公开配置字段，输出时自动进行 HTML 转义。')
            ->parameter('code', required: true, description: '配置编码。')
            ->parameter('field', required: true, description: '字段编码。')
            ->parameter('source', description: '配置来源。', default: 'system', enum: ['system', 'custom'])
            ->commit();
    }

    public function value(array $arguments): mixed
    {
        $source = $arguments['source'];
        $ownerType = $source === 'custom' ? 'app' : 'core';
        $ownerCode = $source === 'custom' ? 'host' : 'ptadmin';
        $configuration = $this->configs->get($ownerType, $ownerCode, $arguments['code']);
        if (($configuration['definition']['exposure'] ?? 'internal') !== 'public'
            || !($configuration['definition']['status'] ?? false)) {
            return null;
        }

        foreach ($configuration['definition']['schema']['fields'] as $field) {
            if ($field['name'] === $arguments['field']) {
                if (FieldProtection::mode($field) !== '' || FieldProtection::hidden($field)) {
                    return null;
                }

                return $configuration['effective_values'][$arguments['field']] ?? null;
            }
        }

        return null;
    }
}
