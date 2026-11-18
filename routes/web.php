<?php

use App\Http\Controllers\ProtegeyDemoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/protegey/report-transaction', [ProtegeyDemoController::class, 'reportTransaction']);
Route::get('/protegey/transactions', [ProtegeyDemoController::class, 'listTransactions']);
Route::get('/protegey/alerts', [ProtegeyDemoController::class, 'listAlerts']);
Route::post('/protegey/start-kyc', [ProtegeyDemoController::class, 'startKyc']);
Route::get('/protegey/kyc-sessions', [ProtegeyDemoController::class, 'listKycSessions']);
Route::post('/protegey/kyb-invite', [ProtegeyDemoController::class, 'inviteKyb']);
Route::post('/protegey/sanctions-screen', [ProtegeyDemoController::class, 'screenSanctions']);
Route::post('/protegey/shared-signal-check', [ProtegeyDemoController::class, 'checkSharedSignal']);
Route::post('/protegey/webhook', [ProtegeyDemoController::class, 'webhook']);
