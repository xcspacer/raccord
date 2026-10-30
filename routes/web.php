<?php

use App\Http\Controllers\AgencySwitchController;
use App\Http\Controllers\AuditBoxScreenController;
use App\Http\Controllers\AuditFileController;
use App\Http\Controllers\AuditOperationController;
use App\Http\Controllers\AuditPdfController;
use App\Http\Controllers\AuditPhotoController;
use App\Http\Controllers\AuditPlanController;
use App\Http\Controllers\AuditPointScreenController;
use App\Http\Controllers\AuditSnapshotController;
use App\Http\Controllers\AuditSynthesisController;
use App\Http\Controllers\AuditTimesheetController;
use App\Http\Controllers\Billing\BillingExpensePhotoController;
use App\Http\Controllers\Billing\BillingExportController;
use App\Http\Controllers\Billing\BillingPhotoController;
use App\Http\Controllers\BrandingLogoController;
use App\Http\Controllers\ChangePasswordController;
use App\Http\Controllers\ClientPortal\ClientNoteAcknowledgeController;
use App\Http\Controllers\CompanyLogoController;
use App\Http\Controllers\DayPunchController;
use App\Http\Controllers\DesignOfficeWorkflow\BoxMessageController;
use App\Http\Controllers\DesignOfficeWorkflow\BoxPositionController;
use App\Http\Controllers\DesignOfficeWorkflow\BoxWorkflowController;
use App\Http\Controllers\DesignOfficeWorkflow\DocumentVersionController;
use App\Http\Controllers\DesignOfficeWorkflow\DossierDownloadController;
use App\Http\Controllers\DesignOfficeWorkflow\OccurrenceController;
use App\Http\Controllers\DesignOfficeWorkflow\PauseController;
use App\Http\Controllers\DesignOfficeWorkflow\PhotoAnnotationController;
use App\Http\Controllers\DesignOfficeWorkflow\RepairWorkflowController;
use App\Http\Controllers\DesignOfficeWorkflow\SynopticCorrectionController;
use App\Http\Controllers\EquipmentReportController;
use App\Http\Controllers\ExpenseReportDownloadController;
use App\Http\Controllers\FibreCode\FibreCodeAppController;
use App\Http\Controllers\InventoryAttestationController;
use App\Http\Controllers\InventoryExportController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MediaLibrary\AuditDeliverablesController;
use App\Http\Controllers\MediaLibrary\MediaLibraryFileController;
use App\Http\Controllers\MediaLibrary\TaskPhotoController;
use App\Http\Controllers\PersonalDataExportController;
use App\Http\Controllers\ReverseGeocodeController;
use App\Http\Controllers\UserDocumentController;
use App\Http\Controllers\VehicleImageController;
use App\Http\Controllers\VehicleReportController;
use App\Http\Controllers\WebManifestController;
use App\Http\Controllers\WeeklyTimesheetController;
use App\Http\Controllers\WorkSiteAttachmentController;
use App\Http\Controllers\WorkSiteMapController;
use App\Livewire\Absences\AbsenceIndex;
use App\Livewire\Admin\AccessManagement;
use App\Livewire\Admin\AgencyIndex;
use App\Livewire\Admin\CompanyIndex;
use App\Livewire\Admin\HolidayIndex;
use App\Livewire\Admin\LogIndex;
use App\Livewire\Admin\PlatformSettings;
use App\Livewire\Admin\Users\UserIndex;
use App\Livewire\Audit\AuditCatalogPage;
use App\Livewire\Audit\AuditIndex;
use App\Livewire\Audit\AuditPage;
use App\Livewire\Audit\AuditTimesheetPage;
use App\Livewire\Audit\BoxRecheckPage;
use App\Livewire\Audit\DrawerRecheckPage;
use App\Livewire\Billing\BillingPage;
use App\Livewire\ClientPortal\ClientControlPage;
use App\Livewire\DesignOfficeWorkflow\CataloguesPage;
use App\Livewire\DesignOfficeWorkflow\ClientClaimsPage;
use App\Livewire\DesignOfficeWorkflow\ClosurePage;
use App\Livewire\DesignOfficeWorkflow\IndicatorsPage;
use App\Livewire\DesignOfficeWorkflow\OccurrenceAiPage;
use App\Livewire\DesignOfficeWorkflow\OccurrencePage;
use App\Livewire\DesignOfficeWorkflow\OccurrencesPage;
use App\Livewire\DesignOfficeWorkflow\QueuePage;
use App\Livewire\DesignOfficeWorkflow\RepairsPage;
use App\Livewire\DesignOfficeWorkflow\RequestPage;
use App\Livewire\ExpenseReports\Editor;
use App\Livewire\FibreCode\FibreCodePage;
use App\Livewire\Home;
use App\Livewire\Inventory\Page as InventoryPage;
use App\Livewire\MediaLibrary\AuditDeliverablesPage;
use App\Livewire\MediaLibrary\MediaLibraryPage;
use App\Livewire\Otdr\OtdrPage;
use App\Livewire\Planning\TaskGrid;
use App\Livewire\Planning\Timeline;
use App\Livewire\TechnicianSpace\Space;
use App\Livewire\WorkSites\WorkSiteIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'password.changed', 'vehicle.cycle'])->group(function () {
    Route::get('/', Home::class)->name('home');
    Route::get('/work-site-files/{attachment}', WorkSiteAttachmentController::class)->name('work-site-attachments.show');
    Route::get('/documents/{document}', UserDocumentController::class)->name('user-documents.show');
    Route::post('/agency/{agency}', AgencySwitchController::class)->name('agency.switch');

    Route::post('/day-punch/{operation}', DayPunchController::class)->whereIn('operation', ['start', 'end', 'declare-end'])->middleware(['module:daily_clock_in', 'throttle:30,1'])->name('day-punch');
    Route::get('/my-space', Space::class)->middleware(['module:technician_space', 'feature:technician_space'])->name('technician-space');
    Route::get('/planning/tasks/timesheet', WeeklyTimesheetController::class)->middleware(['module:daily_clock_in', 'can:export-planning-timesheet'])->name('tasks.timesheet');
    Route::get('/equipment', InventoryPage::class)->middleware(['module:equipment', 'feature:equipment_management'])->name('equipment.index');
    Route::get('/equipment/users/{person}', InventoryPage::class)->middleware(['module:equipment', 'feature:equipment_management'])->whereNumber('person')->name('equipment.users.show');
    Route::get('/equipment/attestations/{attestation}', InventoryAttestationController::class)->middleware('module:equipment')->whereNumber('attestation')->name('equipment.attestations.show');
    Route::get('/equipment/export/{format}', InventoryExportController::class)->middleware(['module:equipment', 'feature:equipment_management'])->whereIn('format', ['excel', 'pdf'])->name('equipment.export');
    Route::post('/audits/{cabinet}/operations', AuditOperationController::class)->middleware(['module:audits', 'throttle:240,1', 'sync.protocol'])->where('cabinet', '[A-Za-z0-9_-]{1,30}')->name('audits.operations');
    Route::post('/audits/{cabinet}/photos', [AuditPhotoController::class, 'store'])->middleware(['module:audits', 'throttle:240,1', 'sync.protocol', 'upload.quota'])->where('cabinet', '[A-Za-z0-9_-]{1,30}')->name('audits.photos.store');
    Route::get('/audits/photos/{photo}/{variant}', [AuditPhotoController::class, 'show'])->middleware('module:audits')->whereNumber('photo')->whereIn('variant', ['thumb', 'full'])->name('audits.photos.show');
    Route::get('/audits/catalog', AuditCatalogPage::class)->middleware('module:audits')->name('audits.catalog');
    Route::get('/audits/optical', AuditIndex::class)->defaults('mode', 'optical')->middleware('module:audits')->name('audits.optical');
    Route::get('/audits/full', AuditIndex::class)->defaults('mode', 'full')->middleware('module:audits')->name('audits.full');
    Route::get('/audits/cabinet/{cabinet}', AuditPage::class)->middleware('module:audits')->where('cabinet', '[A-Za-z0-9_-]{1,30}')->name('audits.show');
    Route::get('/audits/{cabinet}/snapshot', AuditSnapshotController::class)->middleware(['module:audits', 'throttle:240,1', 'sync.protocol'])->where('cabinet', '[A-Za-z0-9_-]{1,30}')->name('audits.snapshot');
    Route::get('/audits/cabinet/{cabinet}/point', AuditPointScreenController::class)->middleware('module:audits')->where('cabinet', '[A-Za-z0-9_-]{1,30}')->name('audits.point');
    Route::get('/audits/cabinet/{cabinet}/box', AuditBoxScreenController::class)->middleware('module:audits')->where('cabinet', '[A-Za-z0-9_-]{1,30}')->name('audits.box');
    Route::get('/audits/cabinet/{cabinet}/recheck/{box}', BoxRecheckPage::class)->middleware('module:audits')->where('cabinet', '[A-Za-z0-9_-]{1,30}')->whereNumber('box')->name('audits.recheck');
    Route::get('/audits/cabinet/{cabinet}/drawer', DrawerRecheckPage::class)->middleware('module:audits')->where('cabinet', '[A-Za-z0-9_-]{1,30}')->name('audits.drawer');
    Route::get('/audits/{cabinet}/synthesis', AuditSynthesisController::class)->middleware('module:audits')->where('cabinet', '[A-Za-z0-9_-]{1,30}')->name('audits.synthesis');
    Route::get('/audits/weekly-timesheet', AuditTimesheetPage::class)->middleware(['module:audits', 'feature:weekly_timesheet'])->name('audits.weekly-timesheet');
    Route::get('/audits/weekly-timesheet/download', AuditTimesheetController::class)->middleware(['module:audits', 'feature:weekly_timesheet'])->name('audits.weekly-timesheet.download');
    Route::get('/audits/{cabinet}/pdf/{type}/{id}', AuditPdfController::class)->middleware('module:audits')->where('cabinet', '[A-Za-z0-9_-]{1,30}')->whereIn('type', ['box', 'point'])->whereNumber('id')->name('audits.pdf');
    Route::get('/audits/{cabinet}/plans/{plan}', [AuditPlanController::class, 'show'])->middleware('module:audits')->where('cabinet', '[A-Za-z0-9_-]{1,30}')->whereNumber('plan')->name('audits.plans.show');
    Route::get('/audits/{cabinet}/plans/{plan}/data', [AuditPlanController::class, 'data'])->middleware('module:audits')->where('cabinet', '[A-Za-z0-9_-]{1,30}')->whereNumber('plan')->name('audits.plans.data');
    Route::get('/audits/{cabinet}/files/{kind}/{plan?}', AuditFileController::class)->middleware('module:audits')->where('cabinet', '[A-Za-z0-9_-]{1,30}')->whereIn('kind', ['synoptic', 'map', 'plan', 'drawer'])->whereNumber('plan')->name('audits.files');
    Route::get('/expense-reports', Editor::class)->middleware(['module:expense_reports', 'feature:expense_reports'])->name('expense-reports');
    Route::get('/expense-reports/{report}/download', ExpenseReportDownloadController::class)->middleware(['module:expense_reports', 'feature:expense_reports'])->whereNumber('report')->name('expense-reports.download');
    Route::get('/my-space/vehicle-report', VehicleReportController::class)->middleware(['module:technician_space', 'feature:technician_space'])->name('vehicle-report');
    Route::get('/my-space/equipment-report', EquipmentReportController::class)->middleware(['module:technician_space', 'module:equipment', 'feature:technician_space'])->name('equipment-report');
    Route::get('/vehicle-images/{type}/{id}/{variant}', VehicleImageController::class)->whereIn('type', ['photo', 'damage', 'expense', 'inventory'])->whereIn('variant', ['thumb', 'full'])->whereNumber('id')->name('vehicle-images.show');
    Route::get('/geo/reverse', ReverseGeocodeController::class)->middleware('throttle:30,1')->name('geo.reverse');
    Route::get('/map/work-sites', WorkSiteMapController::class)->middleware(['module:planning', 'throttle:60,1'])->name('map.work-sites');
    Route::get('/planning', Timeline::class)->middleware('module:planning')->name('planning');
    Route::get('/planning/tasks', TaskGrid::class)->middleware('module:planning')->name('planning.tasks');
    Route::get('/work-sites', WorkSiteIndex::class)->middleware('module:planning')->name('work-sites.index');
    Route::get('/work-sites/create', WorkSiteIndex::class)->middleware('module:planning')->name('work-sites.create');
    Route::get('/absences', AbsenceIndex::class)->middleware(['module:planning', 'feature:absences'])->name('absences.index');
    Route::post('/client-notes/{remark}/acknowledge', ClientNoteAcknowledgeController::class)->middleware(['module:client_portal', 'throttle:60,1'])->whereNumber('remark')->name('client-notes.acknowledge');
    Route::post('/task-photos', [TaskPhotoController::class, 'store'])->middleware(['module:planning', 'module:media_library', 'throttle:120,1', 'upload.quota'])->name('task-photos.store');
    Route::get('/task-photos/{file}/{variant}', [TaskPhotoController::class, 'show'])->middleware('module:media_library')->whereNumber('file')->whereIn('variant', ['thumb', 'full'])->name('task-photos.show');
    Route::middleware(['module:media_library', 'feature:media_library'])->prefix('media-library')->group(function () {
        Route::get('/', MediaLibraryPage::class)->name('media-library');
        Route::get('/files/{file}/{variant}', [MediaLibraryFileController::class, 'show'])->whereNumber('file')->whereIn('variant', ['thumb', 'full'])->name('media-library.files');
        Route::post('/zip', [MediaLibraryFileController::class, 'zip'])->middleware('throttle:20,1')->name('media-library.zip');
        Route::get('/audits', AuditDeliverablesPage::class)->middleware(['module:audits', 'feature:audits'])->name('media-library.audits');
        Route::get('/audits/file', [AuditDeliverablesController::class, 'file'])->middleware(['module:audits', 'feature:audits'])->name('media-library.audits.file');
        Route::post('/audits/zip', [AuditDeliverablesController::class, 'zip'])->middleware(['module:audits', 'feature:audits', 'throttle:20,1'])->name('media-library.audits.zip');
    });
    Route::get('/otdr', OtdrPage::class)->middleware(['module:otdr_acceptance', 'feature:otdr_acceptance'])->name('otdr');
    Route::get('/fibre-code', FibreCodePage::class)->middleware(['module:fibre_code', 'feature:fibre_code'])->name('fibre-code');
    Route::get('/billing', BillingPage::class)->middleware(['module:billing', 'feature:billing'])->name('billing');
    Route::get('/billing/expense-photos/{photo}/{variant}', BillingExpensePhotoController::class)->middleware(['module:billing', 'feature:billing'])->whereNumber('photo')->whereIn('variant', ['thumb', 'full'])->name('billing.expense-photos');
    Route::get('/billing/photos/{photo}/{variant}', BillingPhotoController::class)->middleware(['module:billing', 'feature:billing'])->whereNumber('photo')->whereIn('variant', ['thumb', 'full'])->name('billing.photos');
    Route::middleware(['module:billing', 'feature:billing'])->prefix('billing')->group(function () {
        Route::get('/exports/global', [BillingExportController::class, 'global'])->name('billing.exports.global');
        Route::get('/exports/technicians', [BillingExportController::class, 'technicians'])->name('billing.exports.technicians');
        Route::get('/exports/expenses', [BillingExportController::class, 'expenses'])->name('billing.exports.expenses');
        Route::get('/expenses/{expense}/export', [BillingExportController::class, 'expense'])->whereNumber('expense')->name('billing.expenses.export');
    });
    Route::middleware(['module:audits', 'module:design_office_workflow'])->prefix('design-office')->group(function () {
        Route::get('/', QueuePage::class)->middleware('feature:design_office_workflow')->name('design-office.queue');
        Route::get('/occurrences', OccurrencesPage::class)->middleware('feature:design_office_workflow')->name('design-office.occurrences');
        Route::get('/occurrences/{occurrence}', OccurrencePage::class)->middleware('feature:design_office_workflow')->whereNumber('occurrence')->name('design-office.occurrences.show');
        Route::get('/catalogues', CataloguesPage::class)->middleware('feature:design_office_workflow')->name('design-office.catalogues');
        Route::get('/occurrences/{occurrence}/ai', OccurrenceAiPage::class)->middleware('feature:design_office_workflow')->whereNumber('occurrence')->name('design-office.occurrences.ai');
        Route::get('/client-claims', ClientClaimsPage::class)->middleware(['module:client_portal', 'feature:design_office_workflow'])->name('design-office.claims');
        Route::get('/indicators', IndicatorsPage::class)->name('design-office.indicators');
        Route::middleware('throttle:120,1')->prefix('occurrences/{occurrence}')->whereNumber('occurrence')->group(function () {
            Route::post('/photos', [OccurrenceController::class, 'linkPhoto'])->name('design-office.occurrences.photos');
            Route::post('/hold', [OccurrenceController::class, 'hold'])->name('design-office.occurrences.hold');
            Route::post('/correct', [OccurrenceController::class, 'correct'])->name('design-office.occurrences.correct');
            Route::post('/boxes', [OccurrenceController::class, 'proposeBox'])->name('design-office.occurrences.boxes.propose');
            Route::post('/boxes/{box}/decide', [OccurrenceController::class, 'decideBox'])->whereNumber('box')->name('design-office.occurrences.boxes.decide');
            Route::post('/boxes/{box}/result', [OccurrenceController::class, 'result'])->whereNumber('box')->name('design-office.occurrences.boxes.result');
        });
        Route::get('/requests/{validationRequest}', RequestPage::class)->middleware('feature:design_office_workflow')->whereNumber('validationRequest')->name('design-office.requests.show');
        Route::get('/closure', ClosurePage::class)->middleware('feature:design_office_workflow')->name('design-office.closure');
        Route::get('/repairs', RepairsPage::class)->middleware('feature:design_office_workflow')->name('design-office.repairs');
        Route::get('/interventions', [RepairWorkflowController::class, 'interventions'])->middleware('throttle:120,1')->name('design-office.interventions.index');
        Route::middleware('throttle:120,1')->prefix('boxes/{box}')->whereNumber('box')->group(function () {
            Route::get('/', [BoxWorkflowController::class, 'show'])->name('design-office.boxes.show');
            Route::post('/claim', [BoxWorkflowController::class, 'claim'])->name('design-office.boxes.claim');
            Route::post('/hand-over', [BoxWorkflowController::class, 'handOver'])->name('design-office.boxes.hand-over');
            Route::post('/submit', [BoxWorkflowController::class, 'submit'])->name('design-office.boxes.submit');
            Route::get('/occurrences', [OccurrenceController::class, 'index'])->name('design-office.boxes.occurrences.index');
            Route::post('/occurrences', [OccurrenceController::class, 'store'])->name('design-office.boxes.occurrences.store');
            Route::post('/submit-repairs', [RepairWorkflowController::class, 'submit'])->name('design-office.boxes.submit-repairs');
            Route::post('/messages', [BoxMessageController::class, 'store'])->name('design-office.boxes.messages.store');
            Route::get('/positions', [BoxPositionController::class, 'show'])->name('design-office.boxes.positions');
            Route::post('/positions/propose', [BoxPositionController::class, 'propose'])->name('design-office.boxes.positions.propose');
            Route::post('/pause', [PauseController::class, 'start'])->name('design-office.boxes.pause');
            Route::post('/resume', [PauseController::class, 'end'])->name('design-office.boxes.resume');
        });
        Route::middleware('throttle:120,1')->prefix('audits/{audit}')->whereNumber('audit')->group(function () {
            Route::get('/documents', [DocumentVersionController::class, 'index'])->name('design-office.audits.documents');
            Route::get('/synoptic-corrections', [SynopticCorrectionController::class, 'index'])->name('design-office.audits.synoptic-corrections');
            Route::post('/synoptic-corrections', [SynopticCorrectionController::class, 'store'])->name('design-office.audits.synoptic-corrections.store');
        });
        Route::get('/documents/{version}/download', [DocumentVersionController::class, 'download'])->whereNumber('version')->name('design-office.documents.download');
        Route::middleware(['feature:design_office_workflow', 'throttle:20,1'])->prefix('dossiers/{dossierVersion}')->whereNumber('dossierVersion')->group(function () {
            Route::get('/full', [DossierDownloadController::class, 'full'])->name('design-office.dossiers.full');
            Route::get('/changes', [DossierDownloadController::class, 'changes'])->name('design-office.dossiers.changes');
        });
        Route::post('/messages/{message}/resolve', [BoxMessageController::class, 'resolve'])->middleware('throttle:120,1')->whereNumber('message')->name('design-office.messages.resolve');
        Route::get('/photos/{photo}/annotations', [PhotoAnnotationController::class, 'show'])->whereNumber('photo')->name('design-office.photos.annotations');
        Route::put('/photos/{photo}/annotations', [PhotoAnnotationController::class, 'update'])->middleware('throttle:60,1')->whereNumber('photo')->name('design-office.photos.annotations.update');
    });
    Route::get('/client-control', ClientControlPage::class)->middleware(['module:audits', 'module:client_portal', 'feature:client_control'])->name('client-control');

    Route::middleware('can:manage-platform')->prefix('admin')->group(function () {
        Route::get('/access', AccessManagement::class)->name('access.index');
        Route::get('/settings', PlatformSettings::class)->name('platform-settings');
        Route::get('/agencies', AgencyIndex::class)->name('agencies.index');
        Route::get('/companies', CompanyIndex::class)->name('companies.index');
        Route::get('/companies/{company}/logo', CompanyLogoController::class)->whereNumber('company')->name('companies.logo');
        Route::get('/holidays', HolidayIndex::class)->name('holidays.index');
        Route::get('/logs', LogIndex::class)->name('logs.index');
        Route::get('/users', UserIndex::class)->name('users.index');
        Route::get('/users/{user}/personal-data', PersonalDataExportController::class)->whereNumber('user')->middleware('throttle:10,1')->name('users.personal-data');
    });
});

Route::get('/password/change', ChangePasswordController::class)
    ->middleware('auth')
    ->name('password.change');

Route::post('/locale', LocaleController::class)
    ->middleware('throttle:30,1')
    ->name('locale.update');

Route::get('/branding/logo', BrandingLogoController::class)->name('branding.logo');

Route::get('/manifest.webmanifest', WebManifestController::class)->name('web-manifest');

// Standalone « Code Fibre » app: signed-in only (D8), installable and usable offline once opened (its service worker is public/fibre-code-service-worker.js).
Route::middleware('module:fibre_code')->prefix('fibre-code-app')->group(function () {
    Route::get('/', [FibreCodeAppController::class, 'show'])->middleware(['auth', 'feature:fibre_code'])->name('fibre-code.app');
    Route::get('/manifest.webmanifest', [FibreCodeAppController::class, 'manifest'])->name('fibre-code.app.manifest');
});
