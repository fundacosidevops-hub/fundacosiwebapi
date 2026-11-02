<?php

use App\Http\Controllers\Accounting\AccountingController;
use App\Http\Controllers\Accounting\ArsController;
use App\Http\Controllers\Accounting\CashSessionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Clinical\AttachmentController;
use App\Http\Controllers\Clinical\EncounterController;
use App\Http\Controllers\Clinical\PatientRecordController;
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
    Route::get('common/get-ticket-location', [CommonController::class, 'getTicketByLocation']);

    Route::middleware('auth:api')->group(function () {
        /* POST  */
        Route::post('save-patient', [AuthController::class, 'savePatient']);
        Route::post('saveUser', [AuthController::class, 'saveUser']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
        Route::post('billing/save-bill', [BillingController::class, 'save']);
        Route::post('billing/save-payment', [BillingController::class, 'savePayment']);
        Route::post('common/skip-turn', [CommonController::class, 'skipTurn']);
        Route::post('common/update-assistances-doctor', [CommonController::class, 'updateAssistancesDoctor']);
        Route::post('common/update-turn-status', [CommonController::class, 'updateTurnStatus']);
        Route::post('common/update-turn-cashier', [CommonController::class, 'updateTurnCashier']);
        Route::post('common/update-relationships', [CommonController::class, 'updateRelationships']);
        Route::post('common/remove-relationship', [CommonController::class, 'removeRelationship']);
        /* GET */
        Route::get('positions', [AuthController::class, 'positions']);
        Route::get('roles', [AuthController::class, 'roles']);
        Route::get('profile', [AuthController::class, 'profile']);
        Route::get('billing/user-info', [BillingController::class, 'getUserBillingInfo']);
        Route::get('auth/get-all-system-user', [AuthController::class, 'getAllSystemUser']);
        Route::get('billing/payment-methods', [BillingController::class, 'getPaymentMethods']);
        Route::get('billing/get-all-cashier-list', [BillingController::class, 'getAllCashierList']);
        Route::get('common/medical-studies', [CommonController::class, 'getStudiesByInsurance']);
        Route::get('common/call-next-queue', [CommonController::class, 'callNextQueue']);
        Route::get('common/get-user-locations', [CommonController::class, 'getUserLocations']);
        Route::get('common/get-nationalities', [CommonController::class, 'getNationalities']);
        Route::get('common/get-all-doctors', [CommonController::class, 'getAllDoctors']);
        Route::get('common/get-all-tickets-location', [CommonController::class, 'getAllTicketsByLocation']);
        Route::get('common/get-relationship-types', [CommonController::class, 'getRelationshipTypes']);

        /* CAJA (cuadre por cajero) */
        Route::get('cash/current', [CashSessionController::class, 'current']);
        Route::post('cash/open', [CashSessionController::class, 'open']);
        Route::post('cash/close', [CashSessionController::class, 'close']);
        Route::post('cash/sessions/{id}/close', [CashSessionController::class, 'close']);
        Route::get('cash/sessions', [CashSessionController::class, 'index']);
        Route::get('cash/sessions/{id}', [CashSessionController::class, 'show']);

        /* CONTABILIDAD */
        Route::prefix('accounting')->group(function () {
            Route::get('summary', [AccountingController::class, 'summary']);
            Route::get('accounts', [AccountingController::class, 'accounts']);
            Route::post('accounts', [AccountingController::class, 'storeAccount']);
            Route::put('accounts/{id}', [AccountingController::class, 'updateAccount']);
            Route::get('mappings', [AccountingController::class, 'mappings']);
            Route::put('mappings', [AccountingController::class, 'updateMappings']);
            Route::get('periods', [AccountingController::class, 'periods']);
            Route::post('periods/{year}/{month}', [AccountingController::class, 'setPeriodStatus']);
            Route::get('entries', [AccountingController::class, 'entries']);
            Route::get('entries/{id}', [AccountingController::class, 'showEntry']);
            Route::post('entries', [AccountingController::class, 'storeEntry']);
            Route::post('entries/{id}/reverse', [AccountingController::class, 'reverseEntry']);
            Route::get('reports/ledger', [AccountingController::class, 'ledger']);
            Route::get('reports/trial-balance', [AccountingController::class, 'trialBalance']);
            Route::get('reports/income-statement', [AccountingController::class, 'incomeStatement']);
            Route::get('reports/balance-sheet', [AccountingController::class, 'balanceSheet']);

            /* Cuentas por cobrar ARS */
            Route::get('ars/receivables', [ArsController::class, 'receivables']);
            Route::get('ars/pending-invoices', [ArsController::class, 'pendingInvoices']);
            Route::get('ars/claims', [ArsController::class, 'claims']);
            Route::post('ars/claims', [ArsController::class, 'storeClaim']);
            Route::get('ars/claims/{id}', [ArsController::class, 'showClaim']);
            Route::delete('ars/claims/{id}', [ArsController::class, 'destroyClaim']);
            Route::delete('ars/claims/{id}/items/{itemId}', [ArsController::class, 'removeItem']);
            Route::post('ars/claims/{id}/send', [ArsController::class, 'sendClaim']);
            Route::post('ars/claims/{id}/payments', [ArsController::class, 'storePayment']);
        });

        /* HISTORIAL CLÍNICO */
        Route::prefix('clinical')->group(function () {
            Route::get('worklist', [PatientRecordController::class, 'worklist']);
            Route::get('doctors', [PatientRecordController::class, 'doctors']);
            Route::get('patients', [PatientRecordController::class, 'search']);
            Route::get('patients/{id}', [PatientRecordController::class, 'show']);
            Route::put('patients/{id}/background', [PatientRecordController::class, 'saveBackground']);
            Route::post('patients/{id}/allergies', [PatientRecordController::class, 'storeAllergy']);
            Route::delete('patients/{id}/allergies/{allergyId}', [PatientRecordController::class, 'destroyAllergy']);
            Route::post('patients/{id}/medications', [PatientRecordController::class, 'storeMedication']);
            Route::put('patients/{id}/medications/{medId}', [PatientRecordController::class, 'updateMedication']);
            Route::post('patients/{id}/vaccines', [PatientRecordController::class, 'storeVaccine']);
            Route::delete('patients/{id}/vaccines/{vaccineId}', [PatientRecordController::class, 'destroyVaccine']);
            Route::get('patients/{id}/access-log', [PatientRecordController::class, 'accessLog']);
            Route::get('patients/{id}/attachments', [AttachmentController::class, 'index']);
            Route::post('patients/{id}/attachments', [AttachmentController::class, 'store']);
            Route::get('patients/{id}/attachments/{attachmentId}', [AttachmentController::class, 'download']);
            Route::delete('patients/{id}/attachments/{attachmentId}', [AttachmentController::class, 'destroy']);
            Route::post('encounters', [EncounterController::class, 'store']);
            Route::get('encounters/{id}', [EncounterController::class, 'show']);
            Route::put('encounters/{id}', [EncounterController::class, 'update']);
            Route::delete('encounters/{id}', [EncounterController::class, 'destroy']);
            Route::post('encounters/{id}/start', [EncounterController::class, 'start']);
            Route::post('encounters/{id}/vitals', [EncounterController::class, 'storeVitals']);
            Route::post('encounters/{id}/sign', [EncounterController::class, 'sign']);
            Route::post('encounters/{id}/addenda', [EncounterController::class, 'storeAddendum']);
            Route::post('encounters/{id}/print', [EncounterController::class, 'logPrint']);
        });
    });
});
