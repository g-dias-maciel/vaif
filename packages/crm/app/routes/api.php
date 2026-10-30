<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\OnboardController;
use App\Http\Controllers\ReferenceController;
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

Route::post('/onboard/login', [OnboardController::class, 'login'])->middleware('throttle:30,1');

// Internal automation (n8n): attach WhatsApp reference media/links.
Route::post('/references', [ReferenceController::class, 'store'])->middleware('throttle:120,1');
Route::post('/references/analysis', [ReferenceController::class, 'storeAnalysis'])->middleware('throttle:120,1');
