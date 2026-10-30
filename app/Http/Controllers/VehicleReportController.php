<?php

namespace App\Http\Controllers;

use App\Enums\VehiclePhotoSlot;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\VehicleDamage;
use App\Models\VehiclePhoto;
use App\Settings\CompanySettings;
use App\Support\DocumentBrand;
use App\Support\PdfImage;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * « État des lieux » PDF of the signed-in technician's vehicle: current photos and damages noted.
 */
class VehicleReportController extends Controller
{
    public function __invoke(Request $request, CompanySettings $company, DocumentBrand $brand): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $today = CarbonImmutable::now(config('platform.timezone'));
        $photos = VehiclePhoto::query()->where('user_id', $user->id)->whereNull('archived_at')->get()->keyBy(fn (VehiclePhoto $photo) => $photo->slot->value);
        $files = StoredFile::query()->whereIn('id', $photos->pluck('stored_file_id'))->get()->keyBy('id');
        $damages = VehicleDamage::query()->where('user_id', $user->id)->orderBy('occurred_on')->orderBy('id')->get();
        $damageFiles = StoredFile::query()->whereIn('id', $damages->pluck('stored_file_id')->filter())->get()->keyBy('id');
        $vehicle = $user->vehicle;

        $pdf = Pdf::loadView('pdf.vehicle-report', [
            ...$brand->for($user),
            'footer' => $company->document_footer,
            'technician' => $user->name,
            'vehicle' => collect([$vehicle?->model, $vehicle?->plate])->filter()->implode(' — '),
            'date' => $today,
            'slots' => collect(VehiclePhotoSlot::cases())->map(fn (VehiclePhotoSlot $slot) => [
                'label' => $slot->label(),
                'image' => PdfImage::dataUri($files[$photos[$slot->value]->stored_file_id ?? 0] ?? null),
            ])->chunk(2),
            'damages' => $damages->map(fn (VehicleDamage $damage) => [
                'label' => collect([$damage->type?->label(), $damage->details])->filter()->implode(' — '),
                'date' => $damage->occurred_on->translatedFormat('d/m/Y'),
                'image' => PdfImage::dataUri($damageFiles[$damage->stored_file_id ?? 0] ?? null),
            ]),
        ])->setPaper('a4');

        $name = Str::of(__('pdf.vehicle_report.file', ['company' => $company->displayName(), 'name' => $user->name, 'date' => $today->toDateString()]))
            ->ascii()->replaceMatches('/[^A-Za-z0-9 _.\-]/', '')->squish();

        return $pdf->download($name.'.pdf');
    }
}
