<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PharmacyController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'form'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/', [PharmacyController::class, 'page']);
    Route::get('/lookup/products', [PharmacyController::class, 'lookup']);
    Route::get('/lookup/prescriptions', [PharmacyController::class, 'lookupPrescriptions']);
    Route::post('/actions/{action}', [PharmacyController::class, 'action']);
    Route::get('/sales/{id}', [PharmacyController::class, 'sale']);
    Route::get('/prescriptions/{id}', [PharmacyController::class, 'prescription']);
    Route::get('/documents/{kind}/{id}', [PharmacyController::class, 'document']);
    Route::get('/{module}', [PharmacyController::class, 'page']);
});
