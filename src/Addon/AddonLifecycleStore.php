<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use RuntimeException;

final class AddonLifecycleStore
{
    private const TRANSITIONS = [
        'discovered' => ['validating', 'disposing'],
        'validating' => ['loading', 'disposing'],
        'loading' => ['ready', 'disposing'],
        'ready' => ['active', 'inactive', 'disposing'],
        'active' => ['active', 'inactive', 'validating', 'disposing'],
        'inactive' => ['active', 'inactive', 'validating', 'disposing'],
        'disposing' => ['disposed'],
        'disposed' => ['discovered'],
    ];

    public function __construct(private string $root)
    {
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('Unable to create addon lifecycle directory.');
        }
    }

    /** @param array<string, mixed> $meta @return array<string, mixed> */
    public function transition(string $code, string $state, array $meta = []): array
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,63}$/', $code) !== 1 || !array_key_exists($state, self::TRANSITIONS)) {
            throw new AddonManifestException('The addon lifecycle input is invalid.', 'invalid_lifecycle_input');
        }
        $directory = $this->root.'/'.$code;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create addon lifecycle state directory.');
        }
        $path = $directory.'/state.json';
        $handle = fopen($directory.'/.lock', 'c+');
        if (false === $handle) {
            throw new RuntimeException('Unable to open addon lifecycle lock.');
        }
        try {
            flock($handle, LOCK_EX);
            $current = $this->read($code);
            $from = (string) ($current['state'] ?? 'discovered');
            if (null !== $current && !in_array($state, self::TRANSITIONS[$from] ?? [], true)) {
                throw new AddonManifestException('The addon lifecycle transition is invalid.', 'invalid_lifecycle_transition', ['from' => $from, 'to' => $state]);
            }
            $next = array_merge($current ?? [], $meta, ['code' => $code, 'state' => $state, 'updated_at' => gmdate('c')]);
            $tmp = $path.'.tmp-'.bin2hex(random_bytes(4));
            file_put_contents($tmp, json_encode($next, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", LOCK_EX);
            rename($tmp, $path);
            flock($handle, LOCK_UN);
            return $next;
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string, mixed>|null */
    public function read(string $code): ?array
    {
        $path = $this->root.'/'.$code.'/state.json';
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed>|null $snapshot */
    public function restore(string $code, ?array $snapshot): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{1,63}$/', $code) !== 1) {
            throw new AddonManifestException('The addon lifecycle input is invalid.', 'invalid_lifecycle_input');
        }
        $directory = $this->root.'/'.$code;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create addon lifecycle state directory.');
        }
        $path = $directory.'/state.json';
        if (null === $snapshot) {
            @unlink($path);
            return;
        }
        $tmp = $path.'.tmp-'.bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", LOCK_EX);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to restore addon lifecycle state.');
        }
    }
}
