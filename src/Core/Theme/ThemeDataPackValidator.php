<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

use JsonException;

final class ThemeDataPackValidator
{
    /**
     * @param mixed $value
     * @return list<ThemeDataPackDescriptor>
     */
    public function validate(mixed $value, string $themeRoot): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new ThemeException('Theme data_packs must be an array: '.$themeRoot, 'invalid_theme_data_packs', ['directory' => $themeRoot]);
        }

        $packs = [];
        $codes = [];
        foreach ($value as $index => $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw $this->invalid($themeRoot, $index, 'data pack must be an object');
            }
            $code = $item['code'] ?? null;
            $title = $item['title'] ?? null;
            $description = $item['description'] ?? '';
            $file = $item['file'] ?? null;
            if (!is_string($code) || preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $code) !== 1 || in_array($code, $codes, true)) {
                throw $this->invalid($themeRoot, $index, 'data pack code is invalid');
            }
            if (!is_string($title) || $title === '') {
                throw $this->invalid($themeRoot, $index, 'data pack title is required');
            }
            if (!is_string($description)) {
                throw $this->invalid($themeRoot, $index, 'data pack description must be a string');
            }
            if (!is_bool($item['optional'] ?? null)) {
                throw $this->invalid($themeRoot, $index, 'data pack optional must be boolean');
            }
            if (!is_string($file) || !$this->safeFile($file)) {
                throw $this->invalid($themeRoot, $index, 'data pack file must be a relative JSON file under data/');
            }
            $requires = $item['requires'] ?? [];
            if (!is_array($requires) || !array_is_list($requires)) {
                throw $this->invalid($themeRoot, $index, 'data pack requires must be an array');
            }
            foreach ($requires as $resourceKey) {
                if (!is_string($resourceKey) || preg_match('/^[a-z][a-z0-9_-]{0,31}(?:\.[a-z][a-z0-9_-]{0,47})?$/', $resourceKey) !== 1) {
                    throw $this->invalid($themeRoot, $index, 'data pack resource key is invalid');
                }
            }
            $path = $this->resolveFile($themeRoot, $file, $index);
            $this->validateFile($path, $themeRoot, $index, $requires);
            $codes[] = $code;
            $packs[] = new ThemeDataPackDescriptor($code, $title, $description, $item['optional'], $requires, $file, $path);
        }

        return $packs;
    }

    private function safeFile(string $file): bool
    {
        return str_ends_with(strtolower($file), '.json')
            && str_starts_with($file, 'data/')
            && !str_contains($file, '\\')
            && !str_contains($file, '..')
            && preg_match('#^data/[a-zA-Z0-9._/-]+\.json$#', $file) === 1;
    }

    private function resolveFile(string $themeRoot, string $file, int|string $index): string
    {
        $root = realpath($themeRoot);
        $path = realpath($themeRoot.'/'.$file);
        if ($root === false || $path === false || !is_file($path) || !str_starts_with($path, $root.'/')) {
            throw $this->invalid($themeRoot, $index, 'data pack file is unavailable');
        }
        return $path;
    }

    /** @param list<string> $requires */
    private function validateFile(string $path, string $themeRoot, int|string $index, array $requires): void
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw $this->invalid($themeRoot, $index, 'data pack file cannot be read');
        }
        try {
            $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ThemeException('Theme data pack JSON is invalid: '.$path, 'invalid_theme_data_pack', ['file' => $path], $exception);
        }
        if (!is_array($document) || array_is_list($document) || !isset($document['records']) || !is_array($document['records']) || !array_is_list($document['records'])) {
            throw $this->invalid($themeRoot, $index, 'data pack file must contain a records array');
        }
        foreach ($document['records'] as $recordIndex => $record) {
            if (!is_array($record) || array_is_list($record) || !is_string($record['resource'] ?? null) || !is_string($record['key'] ?? null) || $record['key'] === '' || !is_array($record['data'] ?? null) || array_is_list($record['data'])) {
                throw $this->invalid($themeRoot, $index, 'data pack record is invalid at index '.$recordIndex);
            }
            if (!in_array($record['resource'], $requires, true)) {
                throw $this->invalid($themeRoot, $index, 'data pack record resource is not declared in requires');
            }
        }
    }

    private function invalid(string $themeRoot, int|string $index, string $message): ThemeException
    {
        return new ThemeException($message.': '.$themeRoot, 'invalid_theme_data_pack', ['index' => $index, 'directory' => $themeRoot]);
    }
}
