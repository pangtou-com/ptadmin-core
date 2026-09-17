<?php

declare(strict_types=1);

namespace PTAdmin\Core\Install;

use PTAdmin\Core\ApplicationIdentity;
use PTAdmin\Core\Install\Exceptions\InvalidInstallStateException;
use Throwable;

final class InstallStateMachine
{
    public function __construct(
        private InstallStateStore $stateStore,
        private InstallLock $lock,
        private ApplicationIdentity $identity,
        private WebPrefixGenerator $webPrefixGenerator,
        private string $coreVersion
    ) {
    }

    /**
     * Prepares the installation identity and stable public prefix.
     *
     * @return array<string, mixed>
     */
    public function prepare(?string $webPrefix = null): array
    {
        return $this->lock->whileLocked(fn (): array => $this->prepareLocked($webPrefix));
    }

    /**
     * Marks installation complete only after callers have verified migrations and assets.
     *
     * @return array<string, mixed>
     */
    public function complete(): array
    {
        return $this->lock->whileLocked(fn (): array => $this->completeLocked());
    }

    public function fail(Throwable $failure): void
    {
        $this->lock->whileLocked(function () use ($failure): void {
            $this->failLocked($failure);
        });
    }

    /**
     * 在同一把安装锁内完成准备、业务编排和完成标记，避免并发请求交错写入安装状态。
     *
     * @template T
     * @param callable(array<string, mixed>): T $operation
     * @return array{stage:string, installed:bool, state:array<string,mixed>|null, marker:array<string,mixed>|null, result:T|null}
     */
    public function install(callable $operation, ?string $webPrefix = null): array
    {
        return $this->lock->whileLocked(function () use ($operation, $webPrefix): array {
            if ($this->stateStore->isInstalled()) {
                return [
                    'stage' => 'installed',
                    'installed' => true,
                    'state' => $this->stateStore->readInstalled(),
                    'marker' => $this->stateStore->readInstalled(),
                    'result' => null,
                ];
            }

            $prepared = $this->prepareLocked($webPrefix);
            try {
                $result = $operation((array) $prepared['state']);
                $marker = $this->completeLocked();

                return [
                    'stage' => 'installed',
                    'installed' => true,
                    'state' => $this->stateStore->readState(),
                    'marker' => $marker,
                    'result' => $result,
                ];
            } catch (Throwable $failure) {
                $this->failLocked($failure);
                throw $failure;
            }
        });
    }

    /** @return array{stage:string, installed:bool, state:array<string,mixed>|null} */
    private function prepareLocked(?string $requestedPrefix = null): array
    {
        if ($this->stateStore->isInstalled()) {
            return [
                'stage' => 'installed',
                'installed' => true,
                'state' => $this->stateStore->readInstalled(),
            ];
        }

        $state = $this->stateStore->readState() ?? [];
        $this->identity->createForInstall();
        $webPrefix = isset($state['web_prefix'])
            ? $this->webPrefixGenerator->assertValid((string) $state['web_prefix'])
            : ($requestedPrefix !== null && $requestedPrefix !== ''
                ? $this->webPrefixGenerator->assertValid($requestedPrefix)
                : $this->webPrefixGenerator->generate());

        if (isset($state['web_prefix']) && $requestedPrefix !== null && $requestedPrefix !== ''
            && $webPrefix !== $this->webPrefixGenerator->assertValid($requestedPrefix)) {
            throw new InvalidInstallStateException('The PTAdmin web prefix is already fixed for this installation attempt.');
        }

        $state = $this->stateStore->writeState([
            'stage' => 'identity_ready',
            'core_version' => $this->coreVersion,
            'web_prefix' => $webPrefix,
            'failure' => null,
        ]);

        return [
            'stage' => (string) $state['stage'],
            'installed' => false,
            'state' => $state,
        ];
    }

    /** @return array{status:string, core_version:string, web_prefix:string, installed_at:string} */
    private function completeLocked(): array
    {
        $state = $this->stateStore->readState() ?? [];
        $this->identity->load();
        $webPrefix = $this->webPrefixGenerator->assertValid((string) ($state['web_prefix'] ?? ''));
        $marker = $this->stateStore->markInstalled($this->coreVersion, $webPrefix);
        $this->stateStore->writeState(array_merge($state, [
            'stage' => 'installed',
            'core_version' => $this->coreVersion,
            'web_prefix' => $webPrefix,
            'failure' => null,
        ]));

        return $marker;
    }

    private function failLocked(Throwable $failure): void
    {
        $state = $this->stateStore->readState() ?? [];
        $this->stateStore->writeState(array_merge($state, [
            'stage' => 'failed',
            'failure' => [
                'class' => $failure::class,
                'message' => $this->safeFailureMessage($failure->getMessage()),
            ],
        ]));
    }

    private function safeFailureMessage(string $message): string
    {
        $message = preg_replace(
            '/(password|passwd|secret|token|private[_ -]?key)\\s*[=:]\\s*[^\\s,;]+/i',
            '$1=[redacted]',
            $message
        ) ?? $message;

        return substr($message, 0, 500);
    }
}
