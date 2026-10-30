<?php

namespace App\Http\Controllers\DesignOfficeWorkflow;

use App\DesignOfficeWorkflow\PhotoAnnotations;
use App\DesignOfficeWorkflow\WorkflowGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\DesignOfficeWorkflow\SavePhotoAnnotationRequest;
use App\Models\AuditPhoto;
use App\Models\AuditPhotoAnnotation;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A photo of the design office workflow with the shapes drawn over it: the page shows them to whoever works on the
 * audit and lets the design office and the supervisors draw theirs; saving replaces the person's own shapes. The photo
 * file itself is never changed.
 */
class PhotoAnnotationController extends Controller
{
    public function show(Request $request, AuditPhoto $photo, PhotoAnnotations $annotations, WorkflowGuard $guard): View
    {
        Gate::authorize('view', [AuditPhotoAnnotation::class, $photo]);
        $photo->loadMissing(['audit', 'box']);
        $guard->ensureDesignOffice($photo->audit);
        $user = $this->user($request);

        return view('audit.photo-annotations', [
            'photo' => $photo,
            'config' => [
                'userId' => $user->id,
                'canDraw' => $user->can('annotate', [AuditPhotoAnnotation::class, $photo]) && ! $photo->audit->isClosed(),
                'photoUrl' => route('audits.photos.show', ['photo' => $photo->id, 'variant' => 'full'], false),
                'saveUrl' => route('design-office.photos.annotations.update', ['photo' => $photo->id], false),
                'annotations' => $annotations->of($photo),
                'colours' => PhotoAnnotations::COLOURS,
                'texts' => __('design_office_workflow.annotations'),
            ],
        ]);
    }

    public function update(SavePhotoAnnotationRequest $request, AuditPhoto $photo, PhotoAnnotations $annotations): JsonResponse
    {
        Gate::authorize('annotate', [AuditPhotoAnnotation::class, $photo]);
        $annotations->save($this->user($request), $photo, $request->shapes());

        return response()->json(['annotations' => $annotations->of($photo)]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
