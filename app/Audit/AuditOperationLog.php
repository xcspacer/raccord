<?php

namespace App\Audit;

use App\Models\Audit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The operations already applied, per person and operation identifier, so a phone can send its queue again safely.
 * The same identifier sent again with the same content gives back « duplicate »; the same identifier with another
 * content is refused (a phone never does that on its own). Each entry keeps the reception time of the server and the
 * time the phone gave, which is only trusted for an operation that really waited in the phone's queue: at least
 * 2 minutes and at most CLIENT_TIME_TRUSTED_DAYS old, and not older than the last operation of the same person. The gap between the
 * two clocks is kept so the managers can see discrepancies. Entries are removed after the retention period.
 *
 * @phpstan-type Timing array{received_at: CarbonImmutable, performed_at: CarbonImmutable, client_created_at: CarbonImmutable|null, queued: bool}
 */
class AuditOperationLog
{
    public const int RETENTION_DAYS = 180;

    public const int MIN_QUEUED_SECONDS = 120;

    /**
     * How far back the time given by the phone is trusted for what was done on site and waited offline (a weekend
     * without a network included), in days. Shared with the photos (see AuditPhotoStore::CAPTURE_TRUSTED_DAYS).
     */
    public const int CLIENT_TIME_TRUSTED_DAYS = 7;

    /** Above this gap between the phone's clock and the server's, the operation is a discrepancy for the managers. */
    public const int DISCREPANCY_SECONDS = 120;

    /**
     * Fields of an operation that only describe how it travelled and may differ between two sends of the same one.
     */
    private const array TRANSPORT_FIELDS = ['operation_id', 'queued'];

    /**
     * A stable fingerprint of what an operation asks, for the audit it targets.
     *
     * @param  array<string, mixed>  $payload
     */
    public function hash(Audit $audit, string $type, array $payload): string
    {
        $payload = array_diff_key($payload, array_flip(self::TRANSPORT_FIELDS));

        return hash('sha256', json_encode([$audit->id, $type, $this->sorted($payload)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * Whether the person already sent this operation with the same content.
     *
     * @throws AuditOperationException When the identifier was already used for another content.
     */
    public function alreadyApplied(User $user, string $operationId, string $hash): bool
    {
        $applied = DB::table('audit_applied_operations')->where('user_id', $user->id)->where('operation_id', $operationId)->value('payload_hash');

        if ($applied === null) {
            return false;
        }

        if (! hash_equals((string) $applied, $hash)) {
            Log::warning('Audit operation identifier reused with another content', ['user' => $user->id, 'operation' => $operationId]);

            throw new AuditOperationException('operation_id_reused');
        }

        return true;
    }

    /**
     * The times of an operation received now: the business time is the server's unless the phone's clock can be
     * trusted (see the class description).
     *
     * @return Timing
     */
    public function timing(User $user, mixed $clientCreatedAt, bool $queued): array
    {
        $receivedAt = CarbonImmutable::now();
        $clientAt = $this->parse($clientCreatedAt);
        $performedAt = $receivedAt;

        if ($queued && $clientAt !== null) {
            $age = $clientAt->diffInSeconds($receivedAt, false);
            $last = DB::table('audit_applied_operations')->where('user_id', $user->id)->max('performed_at');

            if ($age >= self::MIN_QUEUED_SECONDS && $age <= self::CLIENT_TIME_TRUSTED_DAYS * 86400 && ($last === null || ! $clientAt->isBefore(CarbonImmutable::parse((string) $last)))) {
                $performedAt = $clientAt;
            }
        }

        return ['received_at' => $receivedAt, 'performed_at' => $performedAt, 'client_created_at' => $clientAt, 'queued' => $queued];
    }

    /**
     * @param  Timing  $timing
     */
    public function record(User $user, Audit $audit, string $operationId, string $type, string $hash, array $timing): void
    {
        $clientAt = $timing['client_created_at'];

        DB::table('audit_applied_operations')->insert([
            'user_id' => $user->id, 'audit_id' => $audit->id, 'operation_id' => $operationId, 'type' => mb_substr($type, 0, 30), 'payload_hash' => $hash,
            'queued' => $timing['queued'], 'client_created_at' => $clientAt, 'clock_gap_seconds' => $clientAt === null ? null : (int) $clientAt->diffInSeconds($timing['received_at'], false),
            'performed_at' => $timing['performed_at'], 'received_at' => $timing['received_at'],
        ]);
    }

    /**
     * Removes the entries older than the retention period.
     *
     * @return int How many were removed.
     */
    public function purge(): int
    {
        return DB::table('audit_applied_operations')->where('received_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
    }

    private function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '' || strlen($value) > 40) {
            return null;
        }

        try {
            $at = CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }

        return abs($at->diffInDays(now())) > 366 ? null : $at;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sorted(array $value): array
    {
        ksort($value);

        return array_map(fn (mixed $item): mixed => is_array($item) ? $this->sorted($item) : $item, $value);
    }
}
