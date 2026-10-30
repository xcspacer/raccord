<?php

namespace App\ClientPortal;

use App\Enums\ClientRemarkSide;
use App\Enums\ClientRemarkStatus;
use App\Enums\ClientRemarkTarget;
use App\Enums\Module;
use App\Models\Audit;
use App\Models\ClientAccount;
use App\Models\ClientRemark;
use App\Models\User;
use App\Settings\ModuleSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Notes clients leave in the portal and their threads. A note waits for a technician to take it into account; it
 * asks again when it is reopened or the client writes back. Clients only reach notes of the audits they follow.
 */
class ClientRemarks
{
    public const int MAX_LENGTH = 2000;

    public const int NOTE_MIN = 3;

    public const int REPLY_MIN = 2;

    public function __construct(private ModuleSettings $modules) {}

    /**
     * @throws ValidationException
     */
    public function create(ClientAccount $account, Audit $audit, ClientRemarkTarget $target, ?int $targetId, string $text, string $field = 'text'): ClientRemark
    {
        $this->ensureVisible($account, $audit);
        $body = $this->clean($text, self::NOTE_MIN, $field);
        [$boxId, $pointId, $label] = $this->resolveTarget($audit, $target, $targetId);

        $remark = $audit->clientRemarks()->create([
            'client_account_id' => $account->id,
            'target' => $target,
            'audit_box_id' => $boxId,
            'audit_control_point_id' => $pointId,
            'target_label' => $label,
            'body' => $body,
            'author_name' => $account->name,
            'author_company' => $account->client->name,
            'status' => ClientRemarkStatus::Open,
        ]);
        $this->changed($audit);

        return $remark;
    }

    /**
     * The client writes back: the note is open again and waits again to be taken into account.
     *
     * @throws ValidationException
     */
    public function clientReply(ClientAccount $account, ClientRemark $remark, string $text, string $field = 'text'): void
    {
        $this->ensureVisible($account, $remark->audit);
        $body = $this->clean($text, self::REPLY_MIN, $field);

        $remark->replies()->create(['author_side' => ClientRemarkSide::Client, 'client_account_id' => $account->id, 'author_name' => $account->name, 'body' => $body, 'created_at' => now()]);
        $this->reopen($remark);
    }

    /**
     * @throws ValidationException
     */
    public function staffReply(User $user, ClientRemark $remark, string $text, string $field = 'text'): void
    {
        $body = $this->clean($text, self::REPLY_MIN, $field);

        $remark->replies()->create(['author_side' => ClientRemarkSide::Staff, 'user_id' => $user->id, 'author_name' => $user->name, 'body' => $body, 'created_at' => now()]);
        $remark->update(['status' => ClientRemarkStatus::Answered]);
    }

    public function clientClose(ClientAccount $account, ClientRemark $remark): void
    {
        $this->ensureVisible($account, $remark->audit);
        $this->close($remark);
    }

    public function clientReopen(ClientAccount $account, ClientRemark $remark): void
    {
        $this->ensureVisible($account, $remark->audit);
        $this->reopen($remark);
    }

    public function close(ClientRemark $remark): void
    {
        $remark->update(['status' => ClientRemarkStatus::Closed]);
        $this->changed($remark->audit);
    }

    /**
     * Open again, and technicians are asked again to take it into account.
     */
    public function reopen(ClientRemark $remark): void
    {
        $remark->update(['status' => ClientRemarkStatus::Open, 'acknowledged_by' => null, 'acknowledged_by_name' => null, 'acknowledged_at' => null]);
        $this->changed($remark->audit);
    }

    /**
     * « Prendre en compte »: kept the first time only.
     */
    public function acknowledge(User $user, ClientRemark $remark): void
    {
        Gate::forUser($user)->authorize('acknowledge', $remark);

        if ($remark->acknowledged_at === null) {
            $remark->update(['acknowledged_by' => $user->id, 'acknowledged_by_name' => $user->name, 'acknowledged_at' => now()]);
            $this->changed($remark->audit);
        }
    }

    /**
     * Notes waiting for this person to take them into account, oldest first: managers get every note, technicians
     * the box and control point notes of the audits they work on.
     *
     * @return Collection<int, ClientRemark>
     */
    public function pendingFor(User $user, ?Audit $audit = null): Collection
    {
        if (! $this->modules->isEnabled(Module::ClientPortal) || ! $user->can('viewAny', Audit::class)) {
            return new Collection;
        }

        $remarks = ClientRemark::query()
            ->with('audit')
            ->where('status', '!=', ClientRemarkStatus::Closed->value)
            ->whereNull('acknowledged_at')
            ->when($audit !== null, fn ($query) => $query->where('audit_id', $audit?->id))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return $remarks->filter(fn (ClientRemark $remark) => $this->mayAcknowledge($user, $remark))->values();
    }

    public function mayAcknowledge(User $user, ClientRemark $remark): bool
    {
        return Gate::forUser($user)->allows('acknowledge', $remark);
    }

    /**
     * @return array{0: int|null, 1: int|null, 2: string}
     *
     * @throws ValidationException
     */
    private function resolveTarget(Audit $audit, ClientRemarkTarget $target, ?int $targetId): array
    {
        if ($target === ClientRemarkTarget::Box) {
            $box = $audit->boxes()->whereNull('removed_at')->find($targetId);
            abort_if($box === null, 404);

            return [$box->id, null, $box->name];
        }

        if ($target === ClientRemarkTarget::ControlPoint) {
            $point = $audit->controlPoints()->find($targetId);
            abort_if($point === null, 404);

            return [null, $point->id, $point->name];
        }

        return [null, null, $audit->cabinet_key];
    }

    /**
     * The audit pages and phones reload when the audit revision moves.
     */
    private function changed(Audit $audit): void
    {
        $audit->increment('revision');
    }

    private function ensureVisible(ClientAccount $account, Audit $audit): void
    {
        abort_unless($account->visibleAudits()->whereKey($audit->id)->exists(), 404);
    }

    /**
     * Control characters out, trimmed, and long enough to say something.
     *
     * @throws ValidationException
     */
    private function clean(string $text, int $minimum, string $field): string
    {
        $body = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text));

        if (mb_strlen($body) < $minimum) {
            throw ValidationException::withMessages([$field => __('client_portal.notes.too_short')]);
        }

        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw ValidationException::withMessages([$field => __('client_portal.notes.too_long', ['max' => self::MAX_LENGTH])]);
        }

        return $body;
    }
}
