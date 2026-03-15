<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:api')->group(function () {
        Route::post('saveUser', [AuthController::class, 'saveUser']);
        Route::get('positions', [AuthController::class, 'positions']);
        Route::get('roles', [AuthController::class, 'roles']);
        Route::get('profile', [AuthController::class, 'profile']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('billing/user-info', [BillingController::class, 'getUserBillingInfo']);
        Route::get('billing/insurance', [BillingController::class, 'getInsurance']);
        Route::get('billing/medical-studies', [BillingController::class, 'getStudiesByInsurance']);
        Route::get('billing/catalog-services', [BillingController::class, 'getCatalogServices']);
        Route::get('billing/catalog-services-doctor', [BillingController::class, 'getDoctorsByCatalogServices']);
    });
});
