<?php

namespace App\Http\Controllers;

use App\Actions\DayPunch\RecordDayPunch;
use App\Exceptions\DayPunchException;
use App\Models\DayPunch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * JSON endpoint of the day punch (start, end, declare the end of a forgotten day). The person is always the
 * signed-in technician.
 */
class DayPunchController extends Controller
{
    public function __invoke(Request $request, string $operation, RecordDayPunch $record): JsonResponse
    {
        Gate::authorize('create', DayPunch::class);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'reason' => ['nullable', Rule::in(['denied', 'timeout', 'unavailable', 'network'])],
            'client_at' => ['nullable', 'date'],
            'queued' => ['nullable', 'boolean'],
            'date' => [$operation === 'declare-end' ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'time' => [$operation === 'declare-end' ? 'required' : 'nullable', 'date_format:H:i'],
        ]);

        $geo = [
            'lat' => isset($data['lat']) ? (float) $data['lat'] : null,
            'lng' => isset($data['lng']) ? (float) $data['lng'] : null,
            'accuracy' => isset($data['accuracy']) ? (int) round((float) $data['accuracy']) : null,
            'reason' => $data['reason'] ?? null,
        ];
        $clientAt = isset($data['client_at']) ? CarbonImmutable::parse($data['client_at']) : null;
        $queued = (bool) ($data['queued'] ?? false);

        try {
            $result = match ($operation) {
                'start' => $record->start($user, $geo, $clientAt, $queued),
                'end' => $record->end($user, $geo, $clientAt, $queued),
                'declare-end' => $record->declareEnd($user, (string) $data['date'], (string) $data['time']),
                default => abort(404),
            };
        } catch (DayPunchException $exception) {
            return response()->json(['ok' => false, 'error' => $exception->reason, 'message' => __('day_punch.errors.'.$exception->reason)], $exception->status);
        }

        return response()->json([
            'ok' => true,
            'duplicate' => $result['duplicate'],
            'date' => $result['punch']->date->toDateString(),
            'start' => $result['punch']->startLocal(),
            'end' => $result['punch']->endLocal(),
        ]);
    }
}
