<?php

declare(strict_types=1);

namespace PTAdmin\Template\Directive;

use InvalidArgumentException;
use JsonException;

final class DirectiveDocumentation
{
    public const SCHEMA = 'ptadmin-template-directives@1';
    public const PACKAGE_PATH = 'resources/template-directives.json';
    public const MAX_BYTES = 2 * 1024 * 1024;

    private const MAX_DIRECTIVES = 500;
    private const MAX_PARAMETERS = 200;
    private const MAX_EXAMPLES = 50;
    private const MAX_DEPTH = 24;
    private const MAX_NODES = 50000;
    private const MAX_STRING_BYTES = 262144;
    private const TYPES = ['string', 'integer', 'number', 'boolean', 'null', 'array', 'object', 'mixed'];

    /** @return array<string,mixed>|null */
    public static function fromRegistry(DirectiveRegistry $registry, string $code, string $version): ?array
    {
        self::assertProvider($code, $version);
        $directives = array_filter(
            $registry->metadata(),
            static fn (array $definition): bool => ($definition['addon'] ?? null) === $code,
        );
        if ($directives === []) {
            return null;
        }
        ksort($directives, SORT_STRING);

        $document = [
            'schema' => self::SCHEMA,
            'provider' => ['type' => 'addon', 'code' => $code, 'version' => $version],
            'directives' => $directives,
        ];
        self::validate($document, $code, $version);

        return self::canonicalize($document);
    }

    /** @return array<string,mixed> */
    public static function decode(string $json, ?string $expectedCode = null, ?string $expectedVersion = null): array
    {
        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('模板指令文档为空或超过 2 MiB。');
        }
        try {
            $document = json_decode($json, true, self::MAX_DEPTH + 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('模板指令文档不是有效 JSON。', 0, $exception);
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new InvalidArgumentException('模板指令文档根节点必须是对象。');
        }
        self::validate($document, $expectedCode, $expectedVersion);

        return self::canonicalize($document);
    }

    /** @param array<string,mixed> $document */
    public static function encode(array $document): string
    {
        self::validate($document);
        $json = json_encode(
            self::canonicalize($document),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('模板指令文档超过 2 MiB。');
        }

        return $json;
    }

    public static function manifestPath(array $manifest): ?string
    {
        $release = $manifest['release'] ?? null;
        if ($release === null) {
            return null;
        }
        if (!is_array($release) || array_is_list($release)) {
            throw new InvalidArgumentException('插件 release 声明必须是对象。');
        }
        $documentation = $release['documentation'] ?? null;
        if ($documentation === null) {
            return null;
        }
        if (!is_array($documentation) || array_is_list($documentation)) {
            throw new InvalidArgumentException('插件 release.documentation 声明必须是对象。');
        }
        $path = $documentation['template_directives'] ?? null;
        if ($path === null) {
            return null;
        }
        if ($path !== self::PACKAGE_PATH) {
            throw new InvalidArgumentException('模板指令文档必须位于 '.self::PACKAGE_PATH.'。');
        }

        return $path;
    }

    /** @param array<string,mixed> $document */
    private static function validate(array $document, ?string $expectedCode = null, ?string $expectedVersion = null): void
    {
        self::assertExactKeys($document, ['schema', 'provider', 'directives'], '模板指令文档');
        if (($document['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException('模板指令文档协议版本不受支持。');
        }
        $provider = $document['provider'] ?? null;
        if (!is_array($provider) || array_is_list($provider)) {
            throw new InvalidArgumentException('模板指令文档 provider 必须是对象。');
        }
        self::assertExactKeys($provider, ['type', 'code', 'version'], '模板指令文档 provider');
        if (($provider['type'] ?? null) !== 'addon' || !is_string($provider['code'] ?? null) || !is_string($provider['version'] ?? null)) {
            throw new InvalidArgumentException('模板指令文档 provider 身份无效。');
        }
        $code = $provider['code'];
        $version = $provider['version'];
        self::assertProvider($code, $version);
        if (($expectedCode !== null && $expectedCode !== $code) || ($expectedVersion !== null && $expectedVersion !== $version)) {
            throw new InvalidArgumentException('模板指令文档身份与插件发布版本不一致。');
        }

        $directives = $document['directives'] ?? null;
        if (!is_array($directives) || $directives === [] || array_is_list($directives) || count($directives) > self::MAX_DIRECTIVES) {
            throw new InvalidArgumentException('模板指令文档 directives 必须是非空映射且不能超过 500 项。');
        }
        foreach ($directives as $fullName => $definition) {
            if (!is_string($fullName) || !is_array($definition) || array_is_list($definition)) {
                throw new InvalidArgumentException('模板指令文档包含无效定义。');
            }
            self::assertDirective($fullName, $definition, $code);
        }
        $nodes = 0;
        self::assertJsonValue($document, 0, $nodes);
    }

    /** @param array<string,mixed> $definition */
    private static function assertDirective(string $fullName, array $definition, string $code): void
    {
        $keys = ['name', 'full_name', 'addon', 'type', 'alias', 'description', 'parameters', 'context', 'cache', 'safe_html', 'returns', 'examples'];
        self::assertExactKeys($definition, $keys, '模板指令 '.$fullName);
        $name = $definition['name'] ?? null;
        if (!is_string($name) || preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/D', $name) !== 1
            || $fullName !== $code.'_'.$name || ($definition['full_name'] ?? null) !== $fullName
            || ($definition['addon'] ?? null) !== $code) {
            throw new InvalidArgumentException('模板指令 '.$fullName.' 的名称或所属插件无效。');
        }
        if (!in_array($definition['type'] ?? null, ['output', 'loop', 'branch'], true)
            || (!is_null($definition['alias'] ?? null) && !is_string($definition['alias']))
            || !is_string($definition['description'] ?? null)
            || !is_bool($definition['safe_html'] ?? null)) {
            throw new InvalidArgumentException('模板指令 '.$fullName.' 的基础元数据无效。');
        }
        if (is_string($definition['alias']) && preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/D', $definition['alias']) !== 1) {
            throw new InvalidArgumentException('模板指令 '.$fullName.' 的别名无效。');
        }
        $parameters = $definition['parameters'] ?? null;
        if (!is_array($parameters) || ($parameters !== [] && array_is_list($parameters)) || count($parameters) > self::MAX_PARAMETERS) {
            throw new InvalidArgumentException('模板指令 '.$fullName.' 的参数必须是映射且不能超过 200 项。');
        }
        foreach ($parameters as $parameter => $specification) {
            if (!is_string($parameter) || !is_array($specification) || array_is_list($specification)) {
                throw new InvalidArgumentException('模板指令 '.$fullName.' 包含无效参数声明。');
            }
            self::assertParameter($fullName, $parameter, $specification);
        }
        foreach (['context', 'cache'] as $field) {
            if (!is_array($definition[$field] ?? null)) {
                throw new InvalidArgumentException('模板指令 '.$fullName.' 的 '.$field.' 必须是数组或对象。');
            }
        }
        $returns = $definition['returns'] ?? null;
        if (!is_array($returns) || ($returns !== [] && array_is_list($returns))) {
            throw new InvalidArgumentException('模板指令 '.$fullName.' 的 returns 必须是对象。');
        }
        self::assertReturnShape($returns, '模板指令 '.$fullName.' 的 returns');
        $examples = $definition['examples'] ?? null;
        if (!is_array($examples) || !array_is_list($examples) || count($examples) > self::MAX_EXAMPLES) {
            throw new InvalidArgumentException('模板指令 '.$fullName.' 的 examples 必须是字符串列表且不能超过 50 项。');
        }
        foreach ($examples as $example) {
            if (!is_string($example)) {
                throw new InvalidArgumentException('模板指令 '.$fullName.' 的 examples 必须是字符串列表。');
            }
        }
    }

    /** @param array<string,mixed> $specification */
    private static function assertParameter(string $fullName, string $parameter, array $specification): void
    {
        $keys = ['name', 'type', 'required', 'default', 'has_default', 'description', 'example', 'enum', 'min', 'max', 'item_type', 'deprecated'];
        self::assertExactKeys($specification, $keys, '模板指令 '.$fullName.' 参数 '.$parameter);
        if (($specification['name'] ?? null) !== $parameter || preg_match('/^[a-z][a-z0-9_]*$/D', $parameter) !== 1
            || !in_array($specification['type'] ?? null, self::TYPES, true)
            || !is_bool($specification['required'] ?? null) || !is_bool($specification['has_default'] ?? null)
            || !is_string($specification['description'] ?? null) || !is_bool($specification['deprecated'] ?? null)) {
            throw new InvalidArgumentException('模板指令 '.$fullName.' 参数 '.$parameter.' 的元数据无效。');
        }
        $itemType = $specification['item_type'] ?? null;
        if ($itemType !== null && (!is_string($itemType) || !in_array($itemType, self::TYPES, true) || $specification['type'] !== 'array')) {
            throw new InvalidArgumentException('模板指令 '.$fullName.' 参数 '.$parameter.' 的 item_type 无效。');
        }
        $enum = $specification['enum'] ?? null;
        if ($enum !== null && (!is_array($enum) || !array_is_list($enum))) {
            throw new InvalidArgumentException('模板指令 '.$fullName.' 参数 '.$parameter.' 的 enum 必须是列表。');
        }
        foreach (['min', 'max'] as $field) {
            if (($specification[$field] ?? null) !== null && !is_int($specification[$field]) && !is_float($specification[$field])) {
                throw new InvalidArgumentException('模板指令 '.$fullName.' 参数 '.$parameter.' 的 '.$field.' 必须是数字。');
            }
        }
    }

    /** @param array<string,mixed> $shape */
    private static function assertReturnShape(array $shape, string $label): void
    {
        $allowed = ['description', 'type', 'format', 'fields', 'items', 'variants', 'reference', 'dynamic'];
        $unknown = array_diff(array_keys($shape), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException($label.' 包含未知字段。');
        }
        foreach (['description', 'type', 'format', 'reference'] as $field) {
            if (array_key_exists($field, $shape) && !is_string($shape[$field])) {
                throw new InvalidArgumentException($label.' 的 '.$field.' 必须是字符串。');
            }
        }
        if (array_key_exists('dynamic', $shape) && !is_bool($shape['dynamic'])) {
            throw new InvalidArgumentException($label.' 的 dynamic 必须是布尔值。');
        }
        foreach (['fields', 'variants'] as $field) {
            if (!array_key_exists($field, $shape)) {
                continue;
            }
            $children = $shape[$field];
            if (!is_array($children) || ($children !== [] && array_is_list($children))) {
                throw new InvalidArgumentException($label.' 的 '.$field.' 必须是对象。');
            }
            foreach ($children as $name => $child) {
                if (!is_string($name) || !is_array($child) || array_is_list($child)) {
                    throw new InvalidArgumentException($label.' 的 '.$field.' 包含无效定义。');
                }
                self::assertReturnShape($child, $label.' 的 '.$field.'.'.$name);
            }
        }
        if (array_key_exists('items', $shape)) {
            $items = $shape['items'];
            if (!is_array($items) || array_is_list($items)) {
                throw new InvalidArgumentException($label.' 的 items 必须是对象。');
            }
            self::assertReturnShape($items, $label.' 的 items');
        }
    }

    private static function assertProvider(string $code, string $version): void
    {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $code) !== 1
            || preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $version) !== 1) {
            throw new InvalidArgumentException('模板指令文档的插件编码或版本号无效。');
        }
    }

    /** @param array<array-key,mixed> $value @param list<string> $allowed */
    private static function assertExactKeys(array $value, array $allowed, string $label): void
    {
        $keys = array_keys($value);
        $unknown = array_diff($keys, $allowed);
        $missing = array_diff($allowed, $keys);
        if ($unknown !== [] || $missing !== []) {
            throw new InvalidArgumentException($label.' 的字段不完整或包含未知字段。');
        }
    }

    private static function assertJsonValue(mixed $value, int $depth, int &$nodes): void
    {
        if ($depth > self::MAX_DEPTH || ++$nodes > self::MAX_NODES) {
            throw new InvalidArgumentException('模板指令文档嵌套过深或节点过多。');
        }
        if (is_string($value)) {
            if (strlen($value) > self::MAX_STRING_BYTES) {
                throw new InvalidArgumentException('模板指令文档包含过长字符串。');
            }
            return;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key) && strlen($key) > 255) {
                    throw new InvalidArgumentException('模板指令文档包含过长字段名。');
                }
                self::assertJsonValue($item, $depth + 1, $nodes);
            }
            return;
        }
        if ($value !== null && !is_bool($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('模板指令文档包含无法序列化的值。');
        }
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
