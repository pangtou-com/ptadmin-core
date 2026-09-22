<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

final class ThemeCatalog
{
    public function __construct(
        private string $directory,
        private ThemeManifestValidator $validator,
    ) {
        $this->directory = rtrim($directory, '/');
    }

    /** @return array{themes:list<ThemeDescriptor>,errors:list<array<string,mixed>>} */
    public function scan(): array
    {
        $themes = [];
        $errors = [];
        if (!is_dir($this->directory)) {
            return ['themes' => [], 'errors' => []];
        }

        foreach (glob($this->directory.'/*', GLOB_ONLYDIR) ?: [] as $themeDirectory) {
            $name = basename($themeDirectory);
            if (str_starts_with($name, '.') || preg_match('/^([A-Za-z][A-Za-z0-9._-]{1,63})@(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)$/', $name, $matches) !== 1) {
                continue;
            }
            try {
                $themes[] = $this->validator->validateDirectory($themeDirectory, $matches[1], $matches[2]);
            } catch (ThemeException $exception) {
                $errors[] = $this->error($name, $exception);
            }
        }
        usort($themes, static fn (ThemeDescriptor $left, ThemeDescriptor $right): int => $left->identifier() <=> $right->identifier());

        return ['themes' => $themes, 'errors' => $errors];
    }

    public function find(string $code, string $version): ?ThemeDescriptor
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,63}$/', $code) !== 1 || preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) !== 1) {
            return null;
        }
        $directory = $this->directory.'/'.$code.'@'.$version;
        try {
            return $this->validator->validateDirectory($directory, $code, $version);
        } catch (ThemeException) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function error(string $identifier, ThemeException $exception): array
    {
        return [
            'identifier' => $identifier,
            'error_code' => $exception->errorCode,
            'message' => $exception->getMessage(),
            'details' => $exception->details,
        ];
    }
}
