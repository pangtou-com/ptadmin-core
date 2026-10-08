<?php

declare(strict_types=1);

namespace PTAdmin\Addon\Runtime;

use Closure;
use PTAdmin\Addon\Exception\AddonException;
use Throwable;

/** 暂存插件主动提交的能力，完整校验后提交；不识别具体的能力类型。 */
final class AddonRegistration
{
    private ?string $owner = null;

    /** @var array<string, array{commit: Closure, rollback: Closure}> */
    private array $pending = [];

    /** @var array<string, true> */
    private array $registered = [];

    /** @internal 仅由启动器传入已验证的 active 插件编码。 */
    public function run(string $addonCode, Closure $register): void
    {
        if (isset($this->registered[$addonCode])) {
            return;
        }
        $previousOwner = $this->owner;
        $previousPending = $this->pending;
        $this->owner = $addonCode;
        $this->pending = [];
        try {
            $register();
            $this->commitPending();
            $this->registered[$addonCode] = true;
        } finally {
            $this->owner = $previousOwner;
            $this->pending = $previousPending;
        }
    }

    /** 提交异常时逆序撤销已经执行及当前失败的提交。 */
    private function commitPending(): void
    {
        $applied = [];
        try {
            foreach ($this->pending as $entry) {
                $applied[] = $entry;
                ($entry['commit'])();
            }
        } catch (Throwable $exception) {
            $rollbackErrors = [];
            foreach (array_reverse($applied) as $entry) {
                try {
                    ($entry['rollback'])();
                } catch (Throwable $rollbackException) {
                    $rollbackErrors[] = $rollbackException->getMessage();
                }
            }
            if ($rollbackErrors !== []) {
                throw new AddonException('插件能力注册失败，部分撤销操作也失败：'.$exception->getMessage(), 'capability_rollback_failed', [
                    'registration_error' => $exception->getMessage(),
                    'rollback_errors' => $rollbackErrors,
                ]);
            }
            throw $exception;
        }
    }

    public function assertOwner(string $addonCode): void
    {
        if ($this->owner !== $addonCode) {
            throw new AddonException('能力注册必须在当前插件启动作用域内执行：'.$addonCode, 'capability_owner_invalid');
        }
    }

    /** 校验与声明收集应先完成；回滚只撤销本次提交，不能删除原有注册。 */
    public function stage(string $addonCode, string $key, Closure $commit, Closure $rollback): void
    {
        $this->assertOwner($addonCode);
        if (isset($this->pending[$key])) {
            throw new AddonException('插件重复提交能力注册：'.$addonCode.'/'.$key, 'capability_registration_conflict');
        }
        $this->pending[$key] = ['commit' => $commit, 'rollback' => $rollback];
    }
}
