<?php

namespace App\Http\Controllers;

use App\Audit\AuditOperationException;
use App\Audit\AuditOperations;
use App\Audit\BoxName;
use App\Audit\OpenAudit;
use App\Models\Audit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Receives the operations a person does on an audit (online, or replayed from the phone's queue), one per request and
 * at most 256 KB. The person is always the signed-in user; the answer says whether the operation was a repeat and the
 * new revision.
 */
class AuditOperationController extends Controller
{
    /**
     * Largest body accepted for one operation, in bytes.
     */
    public const int MAX_BODY_BYTES = 256 * 1024;

    public function __invoke(Request $request, string $cabinet, AuditOperations $operations, OpenAudit $open): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if (strlen((string) $request->getContent()) > self::MAX_BODY_BYTES) {
            return $this->failure(new AuditOperationException('payload_too_large', 413));
        }

        $operation = $request->input('op');
        $operationId = (string) data_get($operation, 'operation_id', '');

        if (! is_array($operation)) {
            return $this->failure(new AuditOperationException('bad_request'));
        }

        try {
            $audit = Audit::query()->where('cabinet_key', BoxName::cabinetKey($cabinet))->first();

            if ($audit === null) {
                if (($operation['op'] ?? '') !== 'setup' || ! $user->can('manage', Audit::class)) {
                    throw new AuditOperationException('audit_not_found', 404);
                }

                $audit = $open->handle($user, $cabinet);
            }

            $access = Gate::forUser($user)->inspect('work', $audit);

            if ($access->denied()) {
                throw $access->status() === 404 ? new AuditOperationException('audit_not_found', 404) : new AuditOperationException('forbidden', 403);
            }

            $result = $operations->apply($user, $audit, $operationId, $operation);
        } catch (AuditOperationException $exception) {
            return $this->failure($exception);
        }

        return response()->json(['ok' => true] + $result);
    }

    private function failure(AuditOperationException $exception): JsonResponse
    {
        return response()->json(array_filter(['ok' => false, 'error' => $exception->reason, 'conflict' => $exception->conflict], fn ($value) => $value !== null), $exception->status);
    }
}
