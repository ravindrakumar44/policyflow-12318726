<?php

use App\Http\Controllers\Api\PolicyRequestController;
use Illuminate\Support\Facades\Route;

Route::post('/mock-insurer/callback', [PolicyRequestController::class, 'callback']);
Route::post('/policy-requests/{policyRequest}/submit', [PolicyRequestController::class, 'submit'])
    ->whereNumber('policyRequest');
Route::get('/policy-requests', [PolicyRequestController::class, 'index']);
Route::post('/policy-requests', [PolicyRequestController::class, 'store']);
Route::get('/policy-requests/{policyRequest}', [PolicyRequestController::class, 'show'])
    ->whereNumber('policyRequest');
