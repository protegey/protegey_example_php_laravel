<?php

use App\Http\Controllers\ProtegeyDemoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/protegey/report-transaction', [ProtegeyDemoController::class, 'reportTransaction']);
Route::post('/protegey/start-kyc', [ProtegeyDemoController::class, 'startKyc']);
Route::post('/protegey/webhook', [ProtegeyDemoController::class, 'webhook']);
