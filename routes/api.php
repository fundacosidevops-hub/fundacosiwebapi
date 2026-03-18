<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::post('login', [AuthController::class, 'login']);

    Route::post('sign-print', function (\Illuminate\Http\Request $request) {
        try {
            $toSign = $request->input('request');

            if (! $toSign) {
                return response('No request provided', 400);
            }

            // 2. Cargar la llave privada (ruta: storage/app/private-key.pem)
            $privateKeyPath = storage_path('app/private-key.pem');
            $privateKey = file_get_contents($privateKeyPath);

            // 3. Crear la firma usando RSA-SHA512
            $signature = '';
            $binarySignature = '';

            // OPENSSL_ALGO_SHA512 es el estándar recomendado por QZ
            openssl_sign($toSign, $binarySignature, $privateKey, OPENSSL_ALGO_SHA512);

            // 4. Codificar en Base64 para enviarlo de vuelta
            $signature = base64_encode($binarySignature);

            return response($signature, 200)
                ->header('Content-Type', 'text/plain');

        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Signing failedss',
                'message' => $e->getMessage(),
            ], 500);
        }
    });

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
        Route::post('billing/save-bill', [BillingController::class, 'save']);
    });
});
