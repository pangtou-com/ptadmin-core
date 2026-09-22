<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

use JsonException;

final class ThemeStateStore
{
    public function __construct(private string $stateFile)
    {
    }

    public function write(ThemeDescriptor $theme, ?string $activatedAt = null): void
    {
        $directory = dirname($this->stateFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ThemeException('Theme state directory is unavailable: '.$directory, 'theme_state_unavailable', ['directory' => $directory]);
        }
        $temporary = tempnam($directory, '.theme-state-');
        if ($temporary === false) {
            throw new ThemeException('Theme state temporary file cannot be created.', 'theme_state_unavailable', ['directory' => $directory]);
        }
        try {
            $payload = json_encode([
                'code' => $theme->code,
                'version' => $theme->version,
                'activated_at' => $activatedAt ?? date(DATE_ATOM),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
            if (file_put_contents($temporary, $payload, LOCK_EX) === false || !rename($temporary, $this->stateFile)) {
                throw new ThemeException('Theme state file cannot be replaced: '.$this->stateFile, 'theme_state_unavailable', ['state_file' => $this->stateFile]);
            }
        } catch (JsonException $exception) {
            throw new ThemeException('Theme state cannot be encoded.', 'theme_state_unavailable', [], $exception);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
