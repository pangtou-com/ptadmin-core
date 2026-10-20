<?php

declare(strict_types=1);

namespace PTAdmin\Captcha\Protocol;

use InvalidArgumentException;
use PTAdmin\Captcha\ChallengeType;

final class CaptchaDefinition
{
    public const PROTOCOL_VERSION = 1;

    /** @var string */ private string $code;
    /** @var string */ private string $title;
    /** @var string */ private string $type;
    /** @var int */ private int $protocolVersion;
    /** @var array<int, string> */ private array $operations;
    /** @var string */ private string $assuranceLevel;
    /** @var array<string, mixed> */ private array $renderer;
    /** @var array<string, mixed> */ private array $responseSchema;
    /** @var array<string, mixed> */ private array $configSchema;

    private function __construct(string $code, string $type, string $title, int $protocolVersion, array $operations, string $assuranceLevel, array $renderer, array $responseSchema, array $configSchema)
    {
        $code = trim($code);
        if (1 !== preg_match('/\A[a-z][a-z0-9_-]{0,99}\z/', $code)) {
            throw new InvalidArgumentException('Captcha capability code is invalid.');
        }
        if (self::PROTOCOL_VERSION !== $protocolVersion) {
            throw new InvalidArgumentException('Unsupported captcha protocol version.');
        }
        ChallengeType::assert($type);
        if ([] === $operations || [] !== array_diff($operations, ['create', 'verify', 'refresh'])) {
            throw new InvalidArgumentException('Captcha definition operations are invalid.');
        }
        if (!in_array($type, [ChallengeType::IMAGE_TEXT, ChallengeType::IMAGE_SELECT, ChallengeType::SLIDER, ChallengeType::ROTATE, ChallengeType::WIDGET_TOKEN, ChallengeType::RISK_TOKEN], true)) {
            throw new InvalidArgumentException('Captcha definition type is invalid.');
        }

        $this->code = $code;
        $this->title = '' === trim($title) ? $code : trim($title);
        $this->type = $type;
        $this->protocolVersion = $protocolVersion;
        $this->operations = array_values(array_unique($operations));
        $this->assuranceLevel = '' === trim($assuranceLevel) ? 'standard' : trim($assuranceLevel);
        $this->renderer = $renderer;
        $this->responseSchema = $responseSchema;
        $this->configSchema = $configSchema;
    }

    public static function make(string $code, string $type, array $operations = ['create', 'verify'], array $renderer = [], array $responseSchema = [], array $configSchema = []): self
    {
        return new self($code, $type, $code, self::PROTOCOL_VERSION, $operations, 'standard', $renderer, $responseSchema, $configSchema);
    }

    public static function fromArray(array $data): self
    {
        $definition = new self(
            (string) ($data['code'] ?? ''),
            (string) ($data['type'] ?? ''),
            (string) ($data['title'] ?? ($data['code'] ?? '')),
            (int) ($data['protocol_version'] ?? 0),
            (array) ($data['operations'] ?? []),
            (string) ($data['assurance_level'] ?? 'standard'),
            (array) ($data['renderer'] ?? []),
            (array) ($data['response_schema'] ?? []),
            (array) ($data['config_schema'] ?? [])
        );

        return $definition;
    }

    public function title(string $title): self
    {
        $copy = clone $this;
        $copy->title = '' === trim($title) ? $this->code : trim($title);

        return $copy;
    }

    public function assuranceLevel(string $assuranceLevel): self
    {
        $copy = clone $this;
        $copy->assuranceLevel = trim($assuranceLevel);

        return $copy;
    }

    public function code(): string { return $this->code; }
    public function titleValue(): string { return $this->title; }
    public function type(): string { return $this->type; }
    public function protocolVersion(): int { return $this->protocolVersion; }
    /** @return array<int, string> */ public function operations(): array { return $this->operations; }
    public function supports(string $operation): bool { return in_array($operation, $this->operations, true); }
    public function assuranceLevelValue(): string { return $this->assuranceLevel; }
    /** @return array<string, mixed> */ public function renderer(): array { return $this->renderer; }
    /** @return array<string, mixed> */ public function responseSchema(): array { return $this->responseSchema; }
    /** @return array<string, mixed> */ public function configSchema(): array { return $this->configSchema; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'title' => $this->title,
            'type' => $this->type,
            'protocol_version' => $this->protocolVersion,
            'operations' => $this->operations,
            'assurance_level' => $this->assuranceLevel,
            'renderer' => $this->renderer,
            'response_schema' => $this->responseSchema,
            'config_schema' => $this->configSchema,
        ];
    }
}
