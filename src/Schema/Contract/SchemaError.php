<?php

declare(strict_types=1);

namespace PTAdmin\Schema\Contract;

use JsonSerializable;

/**
 * Schema 对外结构化异常，保留稳定错误码、阶段、详情和批量校验问题。
 */
final class SchemaError extends \RuntimeException implements JsonSerializable
{
    private string $schemaCode;

    /** @var array<string, mixed> */
    public array $details;

    /** @var list<array<string, mixed>> */
    public array $issues;

    public bool $retryable;
    public string $stage;

    /**
     * @param array<string, mixed> $details 错误关联的资源、版本或字段信息。
     * @param list<array<string, mixed>> $issues 一次校验中发现的结构化问题。
     */
    public function __construct(
        string $code,
        string $message,
        array $details = [],
        array $issues = [],
        bool $retryable = false,
        string $stage = Stage::RUNTIME,
    ) {
        parent::__construct($message);
        $this->schemaCode = $code;
        $this->details = $details;
        $this->issues = $issues;
        $this->retryable = $retryable;
        $this->stage = $stage;
    }

    /** 返回跨语言协议定义的字符串错误码，不使用 PHP 异常内建的受保护 code 属性。 */
    public function getSchemaCode(): string
    {
        return $this->schemaCode;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $result = [
            'code' => $this->schemaCode,
            'message' => $this->getMessage(),
            'retryable' => $this->retryable,
            'stage' => $this->stage,
        ];

        if ($this->details !== []) {
            $result['details'] = $this->details;
        }

        if ($this->issues !== []) {
            $result['issues'] = $this->issues;
        }

        return $result;
    }
}
