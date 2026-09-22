<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

use JsonException;

final class ActiveThemeResolver
{
    private ?ThemeDescriptor $active = null;

    /** @var array<string,mixed>|null */
    private ?array $diagnostic = null;

    public function __construct(
        private ThemeCatalog $catalog,
        private string $stateFile,
        private string $defaultCode = 'default',
        private string $defaultVersion = '1.0.0',
    ) {
    }

    public function resolve(): ?ThemeDescriptor
    {
        if ($this->active !== null || $this->diagnostic !== null) {
            return $this->active;
        }

        if (!is_file($this->stateFile)) {
            return $this->resolveDefault('theme_not_selected');
        }
        $raw = file_get_contents($this->stateFile);
        if ($raw === false) {
            $this->diagnostic = ['code' => 'theme_state_unreadable', 'message' => 'Theme state file is unreadable.'];
            return null;
        }
        try {
            $state = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->diagnostic = ['code' => 'theme_state_invalid', 'message' => 'Theme state file is invalid JSON.'];
            return null;
        }
        if (!is_array($state) || array_is_list($state)) {
            $this->diagnostic = ['code' => 'theme_state_invalid', 'message' => 'Theme state must be a JSON object.'];
            return null;
        }

        $code = (string) ($state['code'] ?? '');
        $version = (string) ($state['version'] ?? '');
        if ($code === '' || $version === '') {
            $this->diagnostic = ['code' => 'theme_state_invalid', 'message' => 'Theme state must specify code and version.'];
            return null;
        }
        $theme = $this->catalog->find($code, $version);
        if ($theme === null) {
            $this->diagnostic = ['code' => 'theme_unavailable', 'message' => 'Configured theme is unavailable.', 'code_value' => $code, 'version' => $version];
            return null;
        }
        $this->active = $theme;

        return $theme;
    }

    public function active(): ?ThemeDescriptor
    {
        return $this->resolve();
    }

    public function reset(): void
    {
        $this->active = null;
        $this->diagnostic = null;
    }

    /** @return array<string,mixed>|null */
    public function diagnostic(): ?array
    {
        $this->resolve();

        return $this->diagnostic;
    }

    private function resolveDefault(string $reason): ?ThemeDescriptor
    {
        $theme = $this->catalog->find($this->defaultCode, $this->defaultVersion);
        if ($theme !== null) {
            $this->active = new ThemeDescriptor($theme->code, $theme->version, $theme->directory, $theme->manifest, true);
            return $this->active;
        }
        $this->diagnostic = ['code' => 'default_theme_unavailable', 'message' => 'Default theme is unavailable.', 'reason' => $reason, 'default' => $this->defaultCode.'@'.$this->defaultVersion];

        return null;
    }
}
