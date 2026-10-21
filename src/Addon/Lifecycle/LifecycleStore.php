<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Lifecycle;

use PTAdmin\Addon\Exception\AddonException;
use RuntimeException;

/**
 * 使用独立 JSON 文件持久化每个插件的生命周期状态和诊断元数据。
 *
 * 状态写入通过插件级文件锁和临时文件替换完成，不依赖业务数据库；安装器可保存快照并在文件或资源操作
 * 失败时恢复。该存储只允许显式状态图中的迁移，防止调用方跳过验证、加载或释放阶段。
 */
final class LifecycleStore
{
    /** 生命周期允许的下一状态；同状态迁移仅对 active 和 inactive 开放，用于刷新诊断与 manifest。 */
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

    public function __construct(private readonly string $root)
    {
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('Unable to create addon lifecycle directory.');
        }
    }

    /**
     * 原子写入下一状态，并把 manifest、版本或运行时诊断等元数据合并到现有快照。
     *
     * @param array<string, mixed> $meta 需要随状态保留的业务元数据
     * @return array<string, mixed> 写入后的完整生命周期快照
     */
    public function transition(string $code, string $state, array $meta = []): array
    {
        $code = strtolower($code);
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $code) !== 1 || !array_key_exists($state, self::TRANSITIONS)) {
            throw new AddonException('The addon lifecycle input is invalid.', 'invalid_lifecycle_input');
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
                throw new AddonException('The addon lifecycle transition is invalid.', 'invalid_lifecycle_transition', ['from' => $from, 'to' => $state]);
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

    /** 插件注册快照与生命周期文件使用同一宿主私有存储边界。 */
    public function catalogPath(): string
    {
        return $this->root.'/catalog.json';
    }

    /**
     * 读取插件当前快照；插件从未进入生命周期时返回 null。
     *
     * @return array<string, mixed>|null
     */
    public function read(string $code): ?array
    {
        $code = strtolower($code);
        $path = $this->root.'/'.$code.'/state.json';
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 绕过状态迁移图恢复安装或状态切换前的快照，仅供失败回滚使用。
     *
     * null 表示操作前没有状态文件，此时删除本次操作产生的快照。
     *
     * @param string $code
     * @param array<string, mixed>|null $snapshot 操作开始前保存的完整快照
     * @throws \JsonException
     */
    public function restore(string $code, ?array $snapshot): void
    {
        $code = strtolower($code);
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $code) !== 1) {
            throw new AddonException('The addon lifecycle input is invalid.', 'invalid_lifecycle_input');
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
