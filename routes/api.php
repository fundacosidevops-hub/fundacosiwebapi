<?php

use App\Http\Controllers\Accounting\AccountingController;
use App\Http\Controllers\Accounting\ArsController;
use App\Http\Controllers\Accounting\CashSessionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Clinical\AttachmentController;
use App\Http\Controllers\Clinical\EncounterController;
use App\Http\Controllers\Clinical\PatientRecordController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CatalogAdminController;
use App\Http\Controllers\CommonController;
use App\Http\Controllers\CreditNoteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DgiiController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\NcfSequenceController;
use App\Http\Controllers\UserAdminController;
use App\Http\Middleware\BillingAccess;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\KioskOrStaff;
use App\Http\Middleware\RequireAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Acceso a Facturación: permisos billing.* o rol Caja (admins siempre).
    $billing = RequireAccess::class . BillingAccess::MIDDLEWARE;
    // Límites por IP para rutas públicas (kiosco y pantalla).
    $kioskRate = 'throttle:' . (int) config('kiosk.rate_per_minute', 120) . ',1,kiosk';
    $lookupRate = 'throttle:' . (int) config('kiosk.lookup_per_minute', 20) . ',1,kiosk-lookup';

    Route::post('login', [AuthController::class, 'login']);
    Route::get('avatars/{file}', [UserAdminController::class, 'showAvatar']);

    /* KIOSCO Y PANTALLA: clave de kiosco (X-Kiosk-Key) o empleado con sesión */
    Route::middleware([$kioskRate])->group(function () use ($lookupRate) {
        Route::get('common/patient-info', [CommonController::class, 'getPatientInformation'])
            ->middleware([KioskOrStaff::class . ':billing', $lookupRate]);
        Route::post('common/save-ticket', [CommonController::class, 'saveTicket'])
            ->middleware(KioskOrStaff::class . ':billing');
        Route::get('common/insurance', [CommonController::class, 'getInsurance'])
            ->middleware(KioskOrStaff::class);
        Route::get('common/catalog-services', [CommonController::class, 'getCatalogServices'])
            ->middleware(KioskOrStaff::class);
        Route::get('common/catalog-services-doctor', [CommonController::class, 'getDoctorsByCatalogServices'])
            ->middleware(KioskOrStaff::class);
        Route::get('common/get-ticket-location', [CommonController::class, 'getTicketByLocation'])
            ->middleware(KioskOrStaff::class);
    });

    Route::middleware(['auth:api', EnsureUserIsActive::class, EnsurePasswordIsChanged::class])->group(function () use ($billing) {
        /* SESIÓN Y PERFIL: cualquier usuario con sesión */
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('change-password', [AuthController::class, 'changePassword']);
        Route::get('profile', [AuthController::class, 'profile']);
        /* INICIO (tablero): cualquier usuario; los montos solo con acceso (ver controlador) */
        Route::get('dashboard', [DashboardController::class, 'summary']);
        /* Catálogos de referencia (sin datos personales) */
        Route::get('positions', [AuthController::class, 'positions']);
        Route::get('common/get-user-locations', [CommonController::class, 'getUserLocations']);
        Route::get('common/get-nationalities', [CommonController::class, 'getNationalities']);
        Route::get('common/get-relationship-types', [CommonController::class, 'getRelationshipTypes']);

        /* EMPLEADOS (alta antigua): solo administradores (se valida también en el controlador) */
        Route::post('saveUser', [AuthController::class, 'saveUser'])
            ->middleware(RequireAccess::class . ':role:SuperAdmin,role:Administrador');
        Route::get('roles', [AuthController::class, 'roles'])
            ->middleware([$billing]);

        /* FACTURACIÓN, CAJA Y TURNOS: solo quien tiene acceso a Facturación */
        Route::middleware([$billing])->group(function () {
            Route::post('save-patient', [AuthController::class, 'savePatient']);
            Route::post('billing/save-bill', [BillingController::class, 'save']);
            Route::post('billing/save-payment', [BillingController::class, 'savePayment']);
            Route::post('common/skip-turn', [CommonController::class, 'skipTurn']);
            Route::post('common/update-assistances-doctor', [CommonController::class, 'updateAssistancesDoctor']);
            Route::post('common/update-turn-status', [CommonController::class, 'updateTurnStatus']);
            Route::post('common/update-turn-cashier', [CommonController::class, 'updateTurnCashier']);
            Route::post('common/update-relationships', [CommonController::class, 'updateRelationships']);
            Route::post('common/remove-relationship', [CommonController::class, 'removeRelationship']);
            Route::get('billing/user-info', [BillingController::class, 'getUserBillingInfo']);
            Route::get('auth/get-all-system-user', [AuthController::class, 'getAllSystemUser']);
            Route::get('billing/payment-methods', [BillingController::class, 'getPaymentMethods']);
            Route::get('billing/get-all-cashier-list', [BillingController::class, 'getAllCashierList']);
            Route::get('common/medical-studies', [CommonController::class, 'getStudiesByInsurance']);
            Route::get('common/call-next-queue', [CommonController::class, 'callNextQueue']);
            Route::get('common/get-all-doctors', [CommonController::class, 'getAllDoctors']);
            Route::get('common/get-all-tickets-location', [CommonController::class, 'getAllTicketsByLocation']);
            Route::get('cash/current', [CashSessionController::class, 'current']);
            Route::post('cash/open', [CashSessionController::class, 'open']);
            Route::post('cash/close', [CashSessionController::class, 'close']);
        });

        /* HISTORIAL DE FACTURAS (permiso billing.view o accounting.view, ver controlador) */
        Route::get('billing/invoices', [InvoiceController::class, 'index']);
        Route::get('billing/invoices/cashiers', [InvoiceController::class, 'cashiers']);
        Route::get('billing/invoices/{id}', [InvoiceController::class, 'show'])->whereNumber('id');
        /* ANULACIÓN con nota de crédito B04 (administración y Contabilidad, ver controlador) */
        Route::get('billing/invoices/{id}/annul-preview', [CreditNoteController::class, 'preview'])->whereNumber('id');
        Route::post('billing/invoices/{id}/annul', [CreditNoteController::class, 'annul'])->whereNumber('id');
        /* DGII: formato 607 (administración y Contabilidad, ver controlador) */
        Route::get('dgii/607', [DgiiController::class, 'preview607']);
        Route::get('dgii/607/download', [DgiiController::class, 'download607']);

        /* USUARIOS: mi cuenta y usuarios del sistema (Ajustes) */
        Route::get('users/catalogs', [UserAdminController::class, 'catalogs']);
        Route::get('users/me', [UserAdminController::class, 'me']);
        Route::put('users/me', [UserAdminController::class, 'updateMe']);
        Route::post('users/me/avatar', [UserAdminController::class, 'uploadMyAvatar']);
        Route::delete('users/me/avatar', [UserAdminController::class, 'removeMyAvatar']);
        Route::get('users', [UserAdminController::class, 'index']);
        Route::post('users', [UserAdminController::class, 'store']);
        Route::get('users/{id}', [UserAdminController::class, 'show'])->whereNumber('id');
        Route::put('users/{id}', [UserAdminController::class, 'update'])->whereNumber('id');
        Route::post('users/{id}/password', [UserAdminController::class, 'resetPassword'])->whereNumber('id');
        Route::put('users/{id}/active', [UserAdminController::class, 'setActive'])->whereNumber('id');
        Route::post('users/{id}/avatar', [UserAdminController::class, 'uploadAvatar'])->whereNumber('id');
        Route::delete('users/{id}/avatar', [UserAdminController::class, 'removeAvatar'])->whereNumber('id');

        /* AJUSTES → MANTENIMIENTOS */
        // Médicos por especialidad: administradores y usuarios de Facturación.
        Route::middleware([$billing])->prefix('settings')->group(function () {
            Route::get('doctor-services', [CatalogAdminController::class, 'doctorServices']);
            Route::put('doctors/{id}/service', [CatalogAdminController::class, 'setDoctorService'])->whereNumber('id');
        });
        // Posiciones, comprobantes NCF y bitácora: solo administradores.
        Route::middleware([RequireAccess::class . ':role:SuperAdmin,role:Administrador'])->prefix('settings')->group(function () {
            Route::get('ncf', [NcfSequenceController::class, 'index']);
            Route::post('ncf', [NcfSequenceController::class, 'store']);
            Route::put('ncf/{id}/active', [NcfSequenceController::class, 'setActive'])->whereNumber('id');
            Route::get('audit', [AuditLogController::class, 'index']);
            Route::get('audit/meta', [AuditLogController::class, 'meta']);
            Route::get('positions', [CatalogAdminController::class, 'positions']);
            Route::post('positions', [CatalogAdminController::class, 'storePosition']);
            Route::put('positions/{id}', [CatalogAdminController::class, 'updatePosition'])->whereNumber('id');
        });

        /* CAJA (cuadre): abrir/cerrar la propia está arriba con Facturación;
           cerrar la de otro y ver cuadres lo valida el controlador (accounting.*) */
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
