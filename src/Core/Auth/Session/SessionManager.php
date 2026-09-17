<?php

declare(strict_types=1);

namespace PTAdmin\Core\Auth\Session;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use PTAdmin\Core\Auth\Exceptions\InvalidSessionException;
use RuntimeException;

final class SessionManager
{
    private string $table;
    private int $idleSeconds;
    private int $absoluteSeconds;
    private BearerTokenGenerator $tokens;

    /**
     * @param array{table?:string, idle_minutes?:int, absolute_hours?:int} $config
     */
    public function __construct(ConnectionInterface $database, array $config = [], ?BearerTokenGenerator $tokens = null)
    {
        $this->database = $database;
        $this->table = (string) ($config['table'] ?? 'ptadmin_sessions');
        $this->idleSeconds = max(60, (int) ($config['idle_minutes'] ?? 30) * 60);
        $this->absoluteSeconds = max($this->idleSeconds, (int) ($config['absolute_hours'] ?? 8) * 3600);
        $this->tokens = $tokens ?? new BearerTokenGenerator();
    }

    private ConnectionInterface $database;

    /**
     * @param array<string, mixed> $metadata
     */
    public function issue(string $subjectType, string|int $subjectId, string $guardName, array $metadata = []): SessionToken
    {
        $now = $this->now();
        $absoluteExpiresAt = $now->modify(sprintf('+%d seconds', $this->absoluteSeconds));
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->idleSeconds));
        $token = $this->tokens->generate();

        $id = bin2hex(random_bytes(16));
        $this->database->table($this->table)->insert([
            'id' => $id,
            'token_hash' => $this->tokens->hash($token),
            'subject_type' => $subjectType,
            'subject_id' => (string) $subjectId,
            'guard_name' => $guardName,
            'issued_at' => $this->format($now),
            'expires_at' => $this->format($expiresAt),
            'absolute_expires_at' => $this->format($absoluteExpiresAt),
            'last_used_at' => null,
            'revoked_at' => null,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => $this->format($now),
            'updated_at' => $this->format($now),
        ]);

        return new SessionToken($token, $id, $this->format($expiresAt), $this->format($absoluteExpiresAt));
    }

    /**
     * @return array{id:string, subject_type:string, subject_id:string, guard_name:string, metadata:array<string,mixed>, expires_at:string, absolute_expires_at:string}
     */
    public function resolve(string $token): array
    {
        if ('' === $token) {
            throw new InvalidSessionException('Session token is missing.');
        }

        $row = $this->database->table($this->table)
            ->where('token_hash', $this->tokens->hash($token))
            ->first();
        if (null === $row) {
            throw new InvalidSessionException('Session token is invalid.');
        }

        $now = $this->now();
        if (null !== $row->revoked_at
            || $this->parse((string) $row->expires_at) <= $now
            || $this->parse((string) $row->absolute_expires_at) <= $now) {
            throw new InvalidSessionException('Session token has expired or been revoked.');
        }

        $this->database->table($this->table)->where('id', $row->id)->update([
            'last_used_at' => $this->format($now),
            'updated_at' => $this->format($now),
        ]);

        return [
            'id' => (string) $row->id,
            'subject_type' => (string) $row->subject_type,
            'subject_id' => (string) $row->subject_id,
            'guard_name' => (string) $row->guard_name,
            'metadata' => $this->decodeMetadata($row->metadata),
            'expires_at' => (string) $row->expires_at,
            'absolute_expires_at' => (string) $row->absolute_expires_at,
        ];
    }

    /**
     * @return array{expires_at:string, absolute_expires_at:string}
     */
    public function renew(string $token): array
    {
        $session = $this->resolve($token);
        $now = $this->now();
        $idleExpiresAt = $now->modify(sprintf('+%d seconds', $this->idleSeconds));
        $absoluteExpiresAt = $this->parse($session['absolute_expires_at']);
        $expiresAt = $idleExpiresAt < $absoluteExpiresAt ? $idleExpiresAt : $absoluteExpiresAt;

        $this->database->table($this->table)->where('id', $session['id'])->update([
            'expires_at' => $this->format($expiresAt),
            'last_used_at' => $this->format($now),
            'updated_at' => $this->format($now),
        ]);

        return [
            'expires_at' => $this->format($expiresAt),
            'absolute_expires_at' => $session['absolute_expires_at'],
        ];
    }

    public function revoke(string $token): void
    {
        $hash = $this->tokens->hash($token);
        $this->database->table($this->table)->where('token_hash', $hash)->update([
            'revoked_at' => $this->format($this->now()),
            'updated_at' => $this->format($this->now()),
        ]);
    }

    public function revokeSubject(string $subjectType, string|int $subjectId, string $guardName): int
    {
        return $this->database->table($this->table)
            ->where('subject_type', $subjectType)
            ->where('subject_id', (string) $subjectId)
            ->where('guard_name', $guardName)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $this->format($this->now()),
                'updated_at' => $this->format($this->now()),
            ]);
    }

    /** @return list<array<string,mixed>> */
    public function forSubject(string $subjectType, string|int $subjectId, string $guardName): array
    {
        return $this->database->table($this->table)
            ->where('subject_type', $subjectType)
            ->where('subject_id', (string) $subjectId)
            ->where('guard_name', $guardName)
            ->orderByDesc('issued_at')
            ->get(['id', 'issued_at', 'expires_at', 'absolute_expires_at', 'last_used_at', 'revoked_at', 'metadata'])
            ->map(fn ($row): array => [
                'id' => (string) $row->id,
                'issued_at' => (string) $row->issued_at,
                'expires_at' => (string) $row->expires_at,
                'absolute_expires_at' => (string) $row->absolute_expires_at,
                'last_used_at' => $row->last_used_at,
                'revoked_at' => $row->revoked_at,
                'metadata' => $this->decodeMetadata($row->metadata),
            ])->all();
    }

    public function revokeByIdForSubject(string $sessionId, string $subjectType, string|int $subjectId, string $guardName): bool
    {
        return $this->database->table($this->table)
            ->where('id', $sessionId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', (string) $subjectId)
            ->where('guard_name', $guardName)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $this->format($this->now()), 'updated_at' => $this->format($this->now())]) > 0;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function parse(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception $exception) {
            throw new RuntimeException('Stored session timestamp is invalid.', previous: $exception);
        }
    }

    private function format(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    /** @return array<string, mixed> */
    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (!is_string($metadata) || '' === $metadata) {
            return [];
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }
}
