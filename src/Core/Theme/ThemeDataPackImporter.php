<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

use JsonException;

final class ThemeDataPackImporter
{
    public function __construct(private ThemeDataPackRegistry $registry)
    {
    }

    /** @return array{status:string,missing:list<string>,pack:array<string,mixed>} */
    public function inspect(ThemeDataPackDescriptor $pack): array
    {
        $availability = $this->registry->availability($pack);
        return [
            'status' => $availability['available'] ? 'available' : 'unavailable',
            'missing' => $availability['missing'],
            'pack' => $pack->toArray(),
        ];
    }

    public function import(ThemeDataPackDescriptor $pack): ThemeDataPackImportResult
    {
        $availability = $this->registry->availability($pack);
        if (!$availability['available']) {
            return new ThemeDataPackImportResult('unavailable', errors: array_map(static fn (string $resource): array => ['resource' => $resource, 'error_code' => 'resource_unavailable'], $availability['missing']));
        }
        $raw = file_get_contents($pack->path);
        if ($raw === false) {
            return new ThemeDataPackImportResult('failed', failed: 1, errors: [['error_code' => 'file_unreadable']]);
        }
        try {
            $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return new ThemeDataPackImportResult('failed', failed: 1, errors: [['error_code' => 'invalid_json', 'message' => $exception->getMessage()]]);
        }
        $grouped = [];
        foreach ($document['records'] as $record) {
            $resource = $this->registry->resource($record['resource']);
            if ($resource === null) {
                return new ThemeDataPackImportResult('unavailable', errors: [['resource' => $record['resource'], 'error_code' => 'resource_unavailable']]);
            }
            $this->validateRecord($resource, $record['key'], $record['data']);
            $grouped[$record['resource']][] = ['key' => $record['key'], 'data' => $record['data']];
        }
        $created = $skipped = $failed = 0;
        $hasSkippedStatus = false;
        $errors = [];
        foreach ($grouped as $resourceKey => $records) {
            $result = $this->registry->import($resourceKey, $records);
            $created += $result->created;
            $skipped += $result->skipped;
            $failed += $result->failed;
            $hasSkippedStatus = $hasSkippedStatus || $result->status === 'skipped';
            $errors = array_merge($errors, $result->errors);
        }
        $status = $failed > 0 ? 'failed' : (($hasSkippedStatus || ($created === 0 && $skipped > 0)) ? 'skipped' : 'imported');
        return new ThemeDataPackImportResult($status, $created, $skipped, $failed, $errors);
    }

    /** @param array<string,mixed> $data */
    private function validateRecord(ThemeDataPackResource $resource, string $key, array $data): void
    {
        if (!array_key_exists($resource->keyField, $data) || (string) $data[$resource->keyField] !== $key) {
            throw new ThemeException('Theme data pack stable key does not match resource data.', 'invalid_theme_data_pack_record', ['resource' => $resource->resourceKey, 'key' => $key]);
        }
        foreach ($data as $field => $value) {
            if (!array_key_exists($field, $resource->fields)) {
                throw new ThemeException('Theme data pack field is not declared by the resource.', 'invalid_theme_data_pack_field', ['resource' => $resource->resourceKey, 'field' => $field]);
            }
            if (!$this->typeMatches($value, $resource->fields[$field])) {
                throw new ThemeException('Theme data pack field type is invalid.', 'invalid_theme_data_pack_type', ['resource' => $resource->resourceKey, 'field' => $field, 'type' => $resource->fields[$field]]);
            }
        }
    }

    private function typeMatches(mixed $value, string $type): bool
    {
        if ($value === null) {
            return str_ends_with($type, '?');
        }
        return match (rtrim($type, '?')) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && !array_is_list($value),
            default => false,
        };
    }
}
