<?php

use App\Http\Controllers\ArtistController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordSetupController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\OnboardController;
use App\Http\Controllers\SettingsController;
use App\Models\Lead;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes — Artist-facing CRM
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);

    // First-password invite (artist created by staff).
    Route::get('/password/set/{token}', [PasswordSetupController::class, 'show'])->name('password.set');
    Route::post('/password/set', [PasswordSetupController::class, 'store'])->name('password.set.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');

    // ---- Artist tabs (artist + staff-with-selected-artist) ----
    Route::middleware(['role:artist,staff', 'artist.context'])->group(function () {
        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');

        Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::post('/bookings/block', [BookingController::class, 'block'])->name('bookings.block');

        Route::get('/config', [SettingsController::class, 'index'])->name('settings.index');
        Route::post('/config/toggle', [SettingsController::class, 'toggle'])->name('settings.toggle');
        Route::post('/config/waha/connect', [SettingsController::class, 'connectWaha'])->name('settings.waha.connect');
        Route::post('/config/waha/restart', [SettingsController::class, 'restartWaha'])->name('settings.waha.restart');
        Route::post('/pricing/bulk', [SettingsController::class, 'storePricingBulk'])->name('pricing.bulk');
        Route::post('/pricing/add-location', [SettingsController::class, 'addLocation'])->name('pricing.add-location');
        Route::post('/pricing/add-size', [SettingsController::class, 'addSize'])->name('pricing.add-size');
    });

    // ---- Shared (artist + staff): lead detail + booking actions ----
    Route::middleware('role:artist,staff')->group(function () {
        Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
        Route::get('/leads/{lead}/conversation', [LeadController::class, 'conversation'])->name('leads.conversation');
        Route::post('/leads/{lead}/notes', [LeadController::class, 'storeNote'])->name('leads.notes.store');
        Route::post('/projects/{project}/lost', [LeadController::class, 'markLost'])->name('projects.mark-lost');

        // Tattoos used to be a separate entity; a Lead is now the project.
        // Keep old links working with a permanent redirect.
        Route::get('/tattoos/{lead}', fn (Lead $lead) => redirect()
            ->route('leads.show', $lead, 301))->name('tattoos.show');

        Route::post('/bookings/{slot}/unblock', [BookingController::class, 'unblock'])->name('bookings.unblock');
        Route::post('/bookings/{slot}/confirm-deposit', [BookingController::class, 'confirmDeposit'])->name('bookings.confirm-deposit');
        Route::post('/bookings/{slot}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
        Route::get('/bookings/{slot}/reschedule', [BookingController::class, 'rescheduleForm'])->name('bookings.reschedule');
        Route::post('/bookings/{slot}/reschedule', [BookingController::class, 'reschedule'])->name('bookings.reschedule.store');
    });

    // ---- VAIF staff (full access + artist management) ----
    Route::middleware('role:staff')->group(function () {
        Route::get('/artists', [ArtistController::class, 'index'])->name('artists.index');
        Route::get('/artists/create', [ArtistController::class, 'create'])->name('artists.create');
        Route::post('/artists', [ArtistController::class, 'store'])->name('artists.store');
        Route::get('/artists/{artist}/select', [ArtistController::class, 'select'])->name('artists.select');
        Route::get('/artists/{artist}', [ArtistController::class, 'show'])->name('artists.show');
        Route::post('/artists/{artist}/invite', [ArtistController::class, 'resendInvite'])->name('artists.invite');
        Route::post('/artists/{artist}/toggle', [ArtistController::class, 'toggleAgent'])->name('artists.toggle');
    });
});
