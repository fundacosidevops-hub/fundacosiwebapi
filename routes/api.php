<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CommonController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::post('login', [AuthController::class, 'login']);
    Route::get('common/patient-info', [CommonController::class, 'getPatientInformation']);
    Route::post('common/save-ticket', [CommonController::class, 'saveTicket']);
    Route::get('common/insurance', [CommonController::class, 'getInsurance']);
    Route::get('common/catalog-services', [CommonController::class, 'getCatalogServices']);
    Route::get('common/catalog-services-doctor', [CommonController::class, 'getDoctorsByCatalogServices']);
    Route::middleware('auth:api')->group(function () {
        /* POST */
        Route::post('saveUser', [AuthController::class, 'saveUser']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('billing/save-bill', [BillingController::class, 'save']);
        Route::post('billing/save-payment', [BillingController::class, 'savePayment']);
        /* GET */
        Route::get('positions', [AuthController::class, 'positions']);
        Route::get('roles', [AuthController::class, 'roles']);
        Route::get('profile', [AuthController::class, 'profile']);
        Route::get('billing/user-info', [BillingController::class, 'getUserBillingInfo']);
        Route::get('billing/payment-methods', [BillingController::class, 'getPaymentMethods']);
        Route::get('common/medical-studies', [CommonController::class, 'getStudiesByInsurance']);
        Route::get('common/call-next-queue', [CommonController::class, 'callNextQueue']);

    });
});
