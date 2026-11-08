<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ArtistController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordSetupController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CalibrationController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CopilotController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\PromotionAdminController;
use App\Http\Controllers\Sales\BacklogController;
use App\Http\Controllers\Sales\CampaignController;
use App\Http\Controllers\Sales\ProspectController;
use App\Http\Controllers\Sales\SalesDashboardController;
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
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1');

    // First-password invite (artist created by staff).
    Route::get('/password/set/{token}', [PasswordSetupController::class, 'show'])->name('password.set');
    Route::post('/password/set', [PasswordSetupController::class, 'store'])->middleware('throttle:5,1')->name('password.set.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');

    // ---- Artist tabs (artist + staff-with-selected-artist) ----
    Route::middleware(['role:artist,staff', 'artist.context'])->group(function () {
        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');

        Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
        Route::delete('/clients/{lead}', [ClientController::class, 'destroy'])->name('clients.destroy');

        Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::post('/bookings/block', [BookingController::class, 'block'])->name('bookings.block');

        Route::get('/config', [SettingsController::class, 'index'])->name('settings.index');
        Route::post('/config/toggle', [SettingsController::class, 'toggle'])->name('settings.toggle');
        Route::post('/config/waha/connect', [SettingsController::class, 'connectWaha'])->name('settings.waha.connect');
        Route::post('/config/waha/relink', [SettingsController::class, 'relinkWaha'])->name('settings.waha.relink');
        Route::post('/config/waha/restart', [SettingsController::class, 'restartWaha'])->name('settings.waha.restart');
        Route::post('/config/payment', [SettingsController::class, 'updatePayment'])->name('settings.payment');
        Route::post('/config/negotiation', [SettingsController::class, 'updateNegotiation'])->name('settings.negotiation');
        Route::post('/config/styles', [SettingsController::class, 'updateStyles'])->name('settings.styles');
        Route::post('/config/studio', [SettingsController::class, 'updateStudio'])->name('settings.studio');
        Route::post('/config/blocked-numbers', [SettingsController::class, 'updateBlockedNumbers'])->name('settings.blocked-numbers');
        Route::post('/config/selling', [SettingsController::class, 'updateSelling'])->name('settings.selling');
        Route::post('/config/google', [SettingsController::class, 'connectGoogle'])->name('settings.google.connect');
        Route::post('/config/google/detect', [SettingsController::class, 'detectGoogleCalendars'])->name('settings.google.detect');
        Route::post('/config/google/sync', [SettingsController::class, 'syncGoogle'])->name('settings.google.sync');
        Route::post('/config/google/recheck', [SettingsController::class, 'recheckGoogle'])->name('settings.google.recheck');
        Route::post('/config/google/disconnect', [SettingsController::class, 'disconnectGoogle'])->name('settings.google.disconnect');
        Route::post('/pricing/bulk', [SettingsController::class, 'storePricingBulk'])->name('pricing.bulk');
        Route::post('/pricing/add-location', [SettingsController::class, 'addLocation'])->name('pricing.add-location');
        Route::post('/pricing/add-size', [SettingsController::class, 'addSize'])->name('pricing.add-size');
    });

    // ---- Shared (artist + staff): lead detail + booking actions ----
    Route::middleware('role:artist,staff')->group(function () {
        Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
        Route::post('/leads/{lead}/agent', [LeadController::class, 'toggleAgent'])->name('leads.agent.toggle');
        Route::post('/leads/{lead}/contact-gate', [LeadController::class, 'decideContactGate'])->name('leads.contact-gate');
        Route::get('/leads/{lead}/conversation', [LeadController::class, 'conversation'])->name('leads.conversation');
        Route::post('/leads/{lead}/notes', [LeadController::class, 'storeNote'])->name('leads.notes.store');
        Route::post('/leads/{lead}/return-chat', [LeadController::class, 'returnChat'])->name('leads.return-chat');
        Route::post('/projects/{project}/schedule', [LeadController::class, 'scheduleProject'])->name('projects.schedule');
        Route::post('/projects/{project}/lost', [LeadController::class, 'markLost'])->name('projects.mark-lost');
        Route::post('/projects/{project}/handoff/accept', [LeadController::class, 'acceptHandoff'])->name('projects.handoff.accept');
        Route::post('/projects/{project}/handoff/return', [LeadController::class, 'returnHandoff'])->name('projects.handoff.return');
        Route::post('/projects/{project}/confirm-deposit', [LeadController::class, 'confirmDeposit'])->name('projects.confirm-deposit');
        Route::post('/projects/{project}/complete', [LeadController::class, 'completeProject'])->name('projects.complete');

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
        Route::get('/artists/{artist}/edit', [ArtistController::class, 'edit'])->name('artists.edit');
        Route::put('/artists/{artist}', [ArtistController::class, 'update'])->name('artists.update');
        Route::delete('/artists/{artist}', [ArtistController::class, 'destroy'])->name('artists.destroy');
        Route::get('/artists/{artist}', [ArtistController::class, 'show'])->name('artists.show');
        Route::post('/artists/{artist}/invite', [ArtistController::class, 'resendInvite'])->name('artists.invite');
        Route::post('/artists/{artist}/toggle', [ArtistController::class, 'toggleAgent'])->name('artists.toggle');

        Route::get('/copilot', [CopilotController::class, 'index'])->name('copilot.index');
        Route::post('/copilot/generate', [CopilotController::class, 'generate'])->name('copilot.generate');
        Route::get('/copilot/{report}', [CopilotController::class, 'show'])->name('copilot.show');

        Route::get('/calibration', [CalibrationController::class, 'index'])->name('calibration.index');
        Route::get('/calibration/{artist}', [CalibrationController::class, 'show'])->name('calibration.show');
        Route::post('/calibration/{artist}/mode', [CalibrationController::class, 'updateMode'])->name('calibration.mode');
        Route::post('/calibration/{artist}/webhook', [CalibrationController::class, 'updateWebhook'])->name('calibration.webhook');

        Route::get('/promotions', [PromotionAdminController::class, 'index'])->name('promotions.index');
        Route::get('/promotions/create', [PromotionAdminController::class, 'create'])->name('promotions.create');
        Route::post('/promotions', [PromotionAdminController::class, 'store'])->name('promotions.store');
        Route::get('/promotions/{promotion}/edit', [PromotionAdminController::class, 'edit'])->name('promotions.edit');
        Route::put('/promotions/{promotion}', [PromotionAdminController::class, 'update'])->name('promotions.update');
        Route::post('/promotions/{promotion}/activate', [PromotionAdminController::class, 'activate'])->name('promotions.activate');
        Route::post('/promotions/{promotion}/pause', [PromotionAdminController::class, 'pause'])->name('promotions.pause');
        Route::post('/promotions/{promotion}/end', [PromotionAdminController::class, 'end'])->name('promotions.end');
    });

    // ---- Internal sales CRM (VAIF's own pipeline) ----
    Route::middleware('role:sales,staff')->prefix('sales')->name('sales.')->group(function () {
        Route::get('/', SalesDashboardController::class)->name('dashboard');
        Route::get('/pipeline', [ProspectController::class, 'index'])->name('pipeline');

        Route::get('/prospects/create', [ProspectController::class, 'create'])->name('prospects.create');
        Route::post('/prospects', [ProspectController::class, 'store'])->name('prospects.store');
        Route::get('/prospects/{prospect}', [ProspectController::class, 'show'])->name('prospects.show');
        Route::get('/prospects/{prospect}/edit', [ProspectController::class, 'edit'])->name('prospects.edit');
        Route::put('/prospects/{prospect}', [ProspectController::class, 'update'])->name('prospects.update');
        Route::post('/prospects/{prospect}/stage', [ProspectController::class, 'updateStage'])->name('prospects.stage');
        Route::post('/prospects/{prospect}/next-action', [ProspectController::class, 'updateNextAction'])->name('prospects.next-action');
        Route::post('/prospects/{prospect}/activities', [ProspectController::class, 'storeActivity'])->name('prospects.activities.store');
        Route::post('/prospects/{prospect}/activities/{activity}/complete', [ProspectController::class, 'completeActivity'])->name('prospects.activities.complete');

        Route::get('/backlog', [BacklogController::class, 'index'])->name('backlog');
        Route::post('/backlog/{prospect}/promote', [BacklogController::class, 'promote'])->name('backlog.promote');
        Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    });
});
