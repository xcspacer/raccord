<?php

use App\Http\Controllers\ClientPortal\PortalFileController;
use App\Http\Controllers\ClientPortal\PortalLogoController;
use App\Http\Controllers\ClientPortal\PortalPasswordController;
use App\Http\Controllers\ClientPortal\PortalSessionController;
use App\Livewire\ClientPortal\PortalPage;
use App\Models\Client;
use Illuminate\Support\Facades\Route;

/*
 * Client portal: pages for the client companies' own accounts (guard "client"), one address per company.
 */
Route::bind('portalClient', fn (string $slug) => Client::query()->where('code', $slug)->where('is_active', true)->firstOrFail());

Route::middleware('module:client_portal')->prefix('portal/{portalClient}')->where(['portalClient' => '[A-Za-z0-9_-]{1,40}'])->name('portal.')->group(function () {
    Route::get('/login', [PortalSessionController::class, 'create'])->name('login');
    Route::post('/login', [PortalSessionController::class, 'store'])->name('login.store');
    Route::post('/logout', [PortalSessionController::class, 'destroy'])->name('logout');
    Route::get('/logo', PortalLogoController::class)->name('logo');
    Route::middleware('client.session')->group(function () {
        Route::get('/', PortalPage::class)->name('home');
        Route::get('/password', [PortalPasswordController::class, 'edit'])->name('password');
        Route::put('/password', [PortalPasswordController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
        Route::get('/photos/{photo}/{variant}', [PortalFileController::class, 'photo'])->whereNumber('photo')->whereIn('variant', ['thumb', 'full'])->name('photos');
        Route::get('/pdf/{type}/{id}', [PortalFileController::class, 'pdf'])->whereIn('type', ['box', 'point'])->whereNumber('id')->name('pdf');
        Route::post('/zip', [PortalFileController::class, 'zip'])->middleware('throttle:20,1')->name('zip');
        Route::get('/dossier/{version}/{archive}', [PortalFileController::class, 'dossier'])->middleware(['module:design_office_workflow', 'throttle:20,1'])->whereNumber('version')->whereIn('archive', ['full', 'changes'])->name('dossier');
    });
});
