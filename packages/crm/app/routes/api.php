<?php

use App\Http\Controllers\Api\NotionSyncController;
use App\Http\Controllers\Api\SalesIngestController;
use App\Http\Controllers\ContactGateController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\OnboardController;
use App\Http\Controllers\ReferenceController;
use App\Http\Controllers\WahaWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::get('/up', HealthController::class);

// WAHA (WhatsApp transport) → the in-CRM agent. Replaces the n8n webhook.
Route::post('/webhooks/waha', WahaWebhookController::class)->middleware('throttle:600,1');

Route::post('/onboard/login', [OnboardController::class, 'login'])->middleware('throttle:30,1');

// LP calculator / diagnostic form → internal sales pipeline.
Route::post('/sales/prospects', SalesIngestController::class)
    ->middleware(['sales.ingest', 'throttle:120,1']);

// n8n Notion pipeline sync → clients + backlog.
Route::post('/sales/notion-sync', NotionSyncController::class)
    ->middleware(['sales.ingest:crm.notion_sync_token', 'throttle:60,1']);

// Internal automation (n8n): attach WhatsApp reference media/links.
Route::post('/references', [ReferenceController::class, 'store'])->middleware('throttle:120,1');
Route::post('/references/analysis', [ReferenceController::class, 'storeAnalysis'])->middleware('throttle:120,1');

// Internal automation (n8n): saved-contact gate on a lead's first contact.
Route::post('/contact-gate', ContactGateController::class)->middleware('throttle:120,1');
