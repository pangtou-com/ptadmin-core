<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Extension;

use PTAdmin\Addon\Frontend\FrontendRegistry;
use PTAdmin\Schema\Contracts\SchemaExtensionValidatorInterface;
use PTAdmin\Schema\Contracts\SchemaExtensionWriterInterface;
use PTAdmin\Schema\Definition\Schema;
use PTAdmin\Schema\Meta\Models\Resource;

/** Core 保存未知扩展；已注册插件自行判断资源归属并校验自己的命名空间。 */
final class ExtensionRegistry
{
    public function __construct(private FrontendRegistry $frontend)
    {
    }

    /** @var array<string,SchemaExtensionValidatorInterface> */
    private array $validators = [];

    public function register(string $namespace, SchemaExtensionValidatorInterface $validator): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $namespace) || isset($this->validators[$namespace])) {
            throw new \InvalidArgumentException('Schema extension namespace is invalid or already registered: '.$namespace);
        }
        $this->validators[$namespace] = $validator;
    }

    public function has(string $namespace): bool
    {
        return isset($this->validators[$namespace]);
    }

    /** @return list<array{code:string,path:string,message:string}> */
    public function validate(ExtensionContext $context): array
    {
        $issues = [];
        if ($context->resource->design_flow_id !== '') {
            $flow = $this->frontend->designFlow($context->resource->design_flow_id);
            if ($flow === null) {
                $issues[] = ['code' => 'design_flow_unavailable', 'path' => 'extensions', 'message' => '资源绑定的插件设计流程不可用，不能发布。'];
            } elseif (isset($flow['extension_namespace']) && !$this->has($flow['extension_namespace'])) {
                $issues[] = ['code' => 'extension_validator_unavailable', 'path' => 'extensions.'.$flow['extension_namespace'], 'message' => '插件扩展校验器不可用，不能发布。'];
            }
        }
        foreach ($this->validators as $validator) {
            array_push($issues, ...$validator->validate($context));
        }
        return $issues;
    }

    public function written(Resource $resource, Schema $schema, int $recordId): void
    {
        foreach ($this->validators as $validator) {
            if ($validator instanceof SchemaExtensionWriterInterface) {
                $validator->written($resource, $schema, $recordId);
            }
        }
    }
}
