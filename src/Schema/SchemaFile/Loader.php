<?php

declare(strict_types=1);

namespace PTAdmin\Schema\SchemaFile;

use PTAdmin\Schema\Definition\Schema;

/**
 * 读取带注释和尾逗号容错的 Schema JSON，并补齐协议默认的 persisted 标记。
 */
final class Loader
{
    /**
     * 解析字符串内容；注释剥离只作用于 JSON 字符串之外，避免误删字段值中的 URL 或文本。
     */
    public function load(string $contents): Schema
    {
        return $this->schemaFromDecoded($this->decode($contents));
    }

    /**
     * 加载代码资源文件的外层信封，同时保留资源元数据和已归一化 Schema。
     *
     * @return array{resource:string,table_name:string,migration_owner:string,schema:Schema,metadata:array<string,mixed>}
     */
    public function loadResource(string $contents): array
    {
        $decoded = $this->decode($contents);

        return $this->resourceFromDecoded($decoded);
    }

    /**
     * 解析严格 JSON 格式的资源文件；JSONC 专属的注释和尾逗号不会在这里被接受。
     *
     * @return array{resource:string,table_name:string,migration_owner:string,schema:Schema,metadata:array<string,mixed>}
     */
    public function loadJsonResource(string $contents): array
    {
        return $this->resourceFromDecoded($this->decodeJson($contents));
    }

    /** @param array<string, mixed> $decoded
     * @return array{resource:string,table_name:string,migration_owner:string,schema:Schema,metadata:array<string,mixed>}
     */
    private function resourceFromDecoded(array $decoded): array
    {
        $resource = trim((string) ($decoded['resource'] ?? ''));
        if ($resource === '') {
            throw new \InvalidArgumentException('Schema resource file requires resource.');
        }
        if (!isset($decoded['schema']) || !is_array($decoded['schema'])) {
            throw new \InvalidArgumentException('Schema resource file requires schema.');
        }

        return [
            'resource' => $resource,
            'table_name' => trim((string) ($decoded['table_name'] ?? '')),
            'migration_owner' => trim((string) ($decoded['migration_owner'] ?? $decoded['runtime_owner'] ?? 'schema')) ?: 'schema',
            'schema' => $this->schemaFromDecoded($decoded['schema']),
            'metadata' => $decoded,
        ];
    }

    /** @return array{resource:string,table_name:string,migration_owner:string,schema:Schema,metadata:array<string,mixed>} */
    public function resourceFromFile(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read schema file: '.$path);
        }

        return str_ends_with(strtolower($path), '.schema.jsonc')
            ? $this->loadResource($contents)
            : $this->loadJsonResource($contents);
    }

    /**
     * 将 JSON 对象转换为协议数组，同时保留空对象与空数组的类型差异。
     *
     * 空 JSON 对象可作为 json 字段的默认值参与 Go checksum；若一律转换为空数组，
     * PHP 会把 {} 序列化成 []，导致同一份 Schema 在跨语言发布校验时产生不同摘要。
     */
    private function normalizeDecodedValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            if ($properties === []) {
                return $value;
            }

            $result = [];
            foreach ($properties as $key => $item) {
                $result[$key] = $this->normalizeDecodedValue($item);
            }

            return $result;
        }
        if (is_array($value)) {
            return array_map(fn ($item) => $this->normalizeDecodedValue($item), $value);
        }

        return $value;
    }

    /**
     * 从文件加载 Schema。文件不可读或内容非法时抛出异常，不静默返回空 Schema。
     */
    public function fromFile(string $path): Schema
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read schema file: '.$path);
        }
        return $this->load($contents);
    }

    /** @return array<string, mixed> */
    private function decode(string $contents): array
    {
        $json = $this->stripComments($contents);
        $json = preg_replace('/,\s*([}\]])/', '$1', $json) ?? $json;
        try {
            $decoded = $this->normalizeDecodedValue(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Schema JSON is invalid.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Schema JSON is invalid.');
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $contents): array
    {
        try {
            $decoded = $this->normalizeDecodedValue(json_decode($contents, false, 512, JSON_THROW_ON_ERROR));
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Schema JSON is invalid.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Schema JSON is invalid.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $decoded */
    private function schemaFromDecoded(array $decoded): Schema
    {
        if (isset($decoded['schema']) && is_array($decoded['schema'])) {
            $decoded = $decoded['schema'];
        }
        if (isset($decoded['fields']) && is_array($decoded['fields'])) {
            foreach ($decoded['fields'] as &$field) {
                if (!array_key_exists('persisted', $field)) {
                    $field['persisted'] = true;
                }
            }
        }

        return new Schema($decoded);
    }

    /**
     * 移除 JSON 注释并保留字符串内容，供协议解析器继续处理尾逗号。
     */
    private function stripComments(string $contents): string
    {
        $result = '';
        $length = strlen($contents);
        $string = false;
        $escape = false;
        for ($i = 0; $i < $length; $i++) {
            $char = $contents[$i];
            $next = $contents[$i + 1] ?? '';
            if ($string) {
                $result .= $char;
                if ($escape) {
                    $escape = false;
                } elseif ($char === '\\') {
                    $escape = true;
                } elseif ($char === '"') {
                    $string = false;
                }
                continue;
            }
            if ($char === '"') {
                $string = true;
                $result .= $char;
                continue;
            }
            if ($char === '/' && $next === '/') {
                while ($i < $length && $contents[$i] !== "\n") {
                    $i++;
                }
                $result .= "\n";
                continue;
            }
            if ($char === '/' && $next === '*') {
                $i += 2;
                while ($i + 1 < $length && !($contents[$i] === '*' && $contents[$i + 1] === '/')) {
                    $i++;
                }
                $i++;
                continue;
            }
            $result .= $char;
        }
        return $result;
    }
}
