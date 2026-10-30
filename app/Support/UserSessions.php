<?php

namespace App\Support;

use App\Enums\SessionEndReason;
use App\Logs\SecurityLog;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The signed-in devices of a staff member (database sessions): listing, remote sign-out, and the
 * "remember me" token. A session ended remotely leaves a short-lived marker keyed by a hash of its id,
 * so the device's next JSON request is answered 401 with the reason (session_revoked / user_disabled).
 */
class UserSessions
{
    /**
     * Request attribute holding the SessionEndReason of a device whose session was just ended.
     */
    public const string END_REASON = 'session_end_reason';

    private const string MARKER_PREFIX = 'ended-session:';

    private const int MARKER_DAYS = 30;

    /**
     * Open sessions, most recent first. Sessions are identified by a hash: the real id never leaves the server.
     *
     * @return list<array{key: string, current: bool, device: string, ip: string|null, last_active_at: Carbon}>
     */
    public function list(User $user, ?string $currentSessionId): array
    {
        $currentKey = $currentSessionId === null ? null : $this->key($currentSessionId);

        return $this->query($user)
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session): array => [
                'key' => $key = $this->key((string) $session->id),
                'current' => $key === $currentKey,
                'device' => $this->device($session->user_agent),
                'ip' => $this->truncatedIp($session->ip_address),
                'last_active_at' => Carbon::createFromTimestamp((int) $session->last_activity),
            ])
            ->values()
            ->all();
    }

    /**
     * Signs one device out (never the current one) and forgets every remembered device, so the
     * revoked one cannot come back through its "remember me" cookie.
     */
    public function revoke(User $user, string $key, ?string $currentSessionId): bool
    {
        $sessionId = $this->query($user)->pluck('id')->first(fn (string $id) => $this->key($id) === $key);

        if ($sessionId === null || $sessionId === $currentSessionId) {
            return false;
        }

        $this->end([$sessionId], SessionEndReason::SessionRevoked);
        $this->forgetRememberedDevices($user);
        $this->log($user, 1, SessionEndReason::SessionRevoked);

        return true;
    }

    /**
     * Signs out every device of the person except the current request's one and forgets the
     * remembered devices. Returns how many sessions were ended.
     */
    public function revokeAll(User $user, ?string $currentSessionId, SessionEndReason $reason = SessionEndReason::SessionRevoked): int
    {
        $ids = $this->query($user)
            ->when($currentSessionId !== null, fn ($query) => $query->where('id', '!=', $currentSessionId))
            ->pluck('id')
            ->all();

        $this->end($ids, $reason);
        $this->forgetRememberedDevices($user);
        $this->log($user, count($ids), $reason);

        return count($ids);
    }

    /**
     * After the person changed their own password: other devices are signed out, and the current
     * device keeps being remembered when the role allows it (the old cookie no longer matches).
     */
    public function keepOnlyCurrentDevice(User $user, ?string $currentSessionId): void
    {
        $this->revokeAll($user, $currentSessionId);
        $this->rememberCurrentDevice($user);
    }

    public function forgetRememberedDevices(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }

    /**
     * Records why these sessions ended, for their devices' next requests.
     *
     * @param  list<string>  $sessionIds
     */
    public function end(array $sessionIds, SessionEndReason $reason): void
    {
        if ($sessionIds === []) {
            return;
        }

        DB::table($this->table())->whereIn('id', $sessionIds)->delete();

        foreach ($sessionIds as $sessionId) {
            $this->markEnded($sessionId, $reason);
        }
    }

    public function markEnded(string $sessionId, SessionEndReason $reason): void
    {
        Cache::put(self::MARKER_PREFIX.$this->key($sessionId), $reason->value, now()->addDays(self::MARKER_DAYS));
    }

    public function endReason(Request $request): ?SessionEndReason
    {
        $reason = $request->attributes->get(self::END_REASON);

        if ($reason instanceof SessionEndReason) {
            return $reason;
        }

        if (! $request->hasSession()) {
            return null;
        }

        return SessionEndReason::tryFrom((string) Cache::get(self::MARKER_PREFIX.$this->key($request->session()->getId())));
    }

    /**
     * The 401 answer of JSON requests without a valid staff session.
     */
    public function unauthenticatedResponse(Request $request): JsonResponse
    {
        $reason = $this->endReason($request);

        return response()->json([
            'message' => __('sessions.end_messages.'.($reason->value ?? 'unauthenticated')),
            'reason' => $reason?->value,
        ], 401);
    }

    private function rememberCurrentDevice(User $user): void
    {
        $policy = SessionPolicy::for($user);
        $guard = Auth::guard('web');

        if (! $policy->remembers() || ! $guard->user()?->is($user)) {
            return;
        }

        Cookie::queue(
            $guard->getRecallerName(),
            $user->getAuthIdentifier().'|'.$user->getRememberToken().'|'.$guard->hashPasswordForCookie($user->getAuthPassword()),
            $policy->rememberMinutes(),
        );
    }

    private function log(User $user, int $sessions, SessionEndReason $reason): void
    {
        if ($sessions > 0) {
            app(SecurityLog::class)->info(SecurityLog::SESSIONS_REVOKED, ['user_id' => $user->id, 'sessions' => $sessions, 'reason' => $reason->value]);
        }
    }

    private function query(User $user): Builder
    {
        return DB::table($this->table())->where('user_id', $user->getKey());
    }

    private function table(): string
    {
        return (string) config('session.table', 'sessions');
    }

    private function key(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    private function device(?string $userAgent): string
    {
        $userAgent = (string) $userAgent;

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'SamsungBrowser/') => 'Samsung Internet',
            str_contains($userAgent, 'Firefox/'), str_contains($userAgent, 'FxiOS/') => 'Firefox',
            str_contains($userAgent, 'Chrome/'), str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        $system = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        $parts = array_filter([$browser, $system]);

        return $parts === [] ? __('sessions.unknown_device') : implode(' · ', $parts);
    }

    private function truncatedIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0', $ip).'/24';
        }

        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
