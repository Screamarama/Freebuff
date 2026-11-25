<?php

use App\Http\Controllers\ContractTemplateController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RentalContractController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

// Entry point: send visitors into the app rather than the stock welcome screen.
Route::get('/', function (\Illuminate\Http\Request $request) {
    return $request->user()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Product + customer management (tenant-scoped by business_id).
// Super admins are redirected to the Tenants summary instead of seeing
// cross-tenant record lists; they manage details via impersonation.
Route::middleware(['auth', 'superadmin.summary', 'role:super_admin,admin,manager,clerk'])->group(function () {
    Route::resource('products', ProductController::class);
    // Rental catalogue: what a unit is, separate from the unit itself.
    Route::resource('equipment-models', \App\Http\Controllers\EquipmentModelController::class)->except(['show']);

    // Received rental equipment waits here until it is set up and committed.
    Route::get('/service-hold', [\App\Http\Controllers\ServiceHoldController::class, 'index'])->name('service-hold.index');
    Route::get('/service-hold/{product}/setup', [\App\Http\Controllers\ServiceHoldController::class, 'setup'])->name('service-hold.setup');
    Route::post('/service-hold/{product}/commit', [\App\Http\Controllers\ServiceHoldController::class, 'commit'])->name('service-hold.commit');
    Route::resource('customers', CustomerController::class);
    Route::get('/customers/create/cancel', [CustomerController::class, 'cancelCreate'])->name('customers.create.cancel');
    Route::get('/customers/{customer}/statement', [CustomerController::class, 'statement'])->name('customers.statement');
    Route::post('/customers/{customer}/account-payment', [CustomerController::class, 'recordAccountPayment'])->name('customers.account-payment');
    Route::patch('/customers/{customer}/account-payments/{payment}/void', [CustomerController::class, 'voidAccountPayment'])->name('customers.account-payments.void');
    Route::resource('reservations', ReservationController::class);
    Route::patch('/reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])->name('reservations.cancel');
    Route::patch('/reservations/{reservation}/complete', [ReservationController::class, 'complete'])->name('reservations.complete');
    Route::post('/reservations/{reservation}/convert', [RentalContractController::class, 'convert'])->name('reservations.convert');
    Route::get('/rental-agreements', [RentalContractController::class, 'index'])->name('rental-agreements.index');
    Route::get('/rental-agreements/create', [RentalContractController::class, 'create'])->name('rental-agreements.create');
    Route::post('/rental-agreements/create/customer', [RentalContractController::class, 'startNewCustomer'])->name('rental-agreements.create.customer');
    Route::post('/rental-agreements', [RentalContractController::class, 'store'])->name('rental-agreements.store');
    Route::get('/rental-agreements/availability', [RentalContractController::class, 'availability'])->name('rental-agreements.availability');
    Route::get('/rental-agreements/{rental_contract}', [RentalContractController::class, 'show'])->name('rental-agreements.show');
    Route::get('/rental-agreements/{rental_contract}/edit', [RentalContractController::class, 'edit'])->name('rental-agreements.edit');
    Route::patch('/rental-agreements/{rental_contract}/return', [RentalContractController::class, 'markReturned'])->name('rental-agreements.return');
    Route::patch('/rental-agreements/{rental_contract}/cancel', [RentalContractController::class, 'cancel'])->name('rental-agreements.cancel');
    Route::post('/rental-agreements/{rental_contract}/items', [RentalContractController::class, 'storeItem'])->name('rental-agreements.items.store');
    Route::patch('/rental-agreements/{rental_contract}/items/{item}', [RentalContractController::class, 'updateItem'])->name('rental-agreements.items.update');
    Route::delete('/rental-agreements/{rental_contract}/items/{item}', [RentalContractController::class, 'destroyItem'])->name('rental-agreements.items.destroy');
    Route::post('/rental-agreements/{rental_contract}/payments', [\App\Http\Controllers\AgreementPaymentController::class, 'store'])->name('rental-agreements.payments.store');
    Route::patch('/rental-agreements/{rental_contract}/payments/{payment}/void', [\App\Http\Controllers\AgreementPaymentController::class, 'void'])->name('rental-agreements.payments.void');
    Route::post('/rental-agreements/{rental_contract}/deposit', [\App\Http\Controllers\AgreementPaymentController::class, 'deposit'])->name('rental-agreements.payments.deposit');
    Route::post('/rental-agreements/{rental_contract}/settle', [\App\Http\Controllers\AgreementPaymentController::class, 'settle'])->name('rental-agreements.payments.settle');
    Route::patch('/rental-agreements/{rental_contract}/settle', [\App\Http\Controllers\AgreementPaymentController::class, 'adjustSettlement'])->name('rental-agreements.payments.adjust');
    Route::post('/rental-agreements/{rental_contract}/refund-credit', [\App\Http\Controllers\AgreementPaymentController::class, 'refundCredit'])->name('rental-agreements.payments.refund-credit');
    Route::post('/rental-agreements/{rental_contract}/deposit-top-up', [\App\Http\Controllers\AgreementPaymentController::class, 'topUpDeposit'])->name('rental-agreements.payments.top-up');
    Route::post('/rental-agreements/{rental_contract}/charge-account', [\App\Http\Controllers\AgreementPaymentController::class, 'chargeToAccount'])->name('rental-agreements.charge-account');
    Route::post('/rental-agreements/{rental_contract}/reverse-charge', [\App\Http\Controllers\AgreementPaymentController::class, 'reverseCharge'])->name('rental-agreements.reverse-charge');
    Route::get('/receivables', [\App\Http\Controllers\AccountsReceivableController::class, 'index'])->name('receivables.index');
    Route::get('/sales-tax', [\App\Http\Controllers\SalesTaxReportController::class, 'index'])->name('sales-tax.index');
    Route::get('/sales-tax/export', [\App\Http\Controllers\SalesTaxReportController::class, 'export'])->name('sales-tax.export');
    Route::post('/sales-tax/filings', [\App\Http\Controllers\SalesTaxReportController::class, 'storeFiling'])->name('sales-tax.filings.store');
    Route::get('/sales-tax/filings/{filing}/export', [\App\Http\Controllers\SalesTaxReportController::class, 'exportFiling'])
        ->name('sales-tax.filings.export');
    Route::get('/sales-tax/filings/{filing}/amendment', [\App\Http\Controllers\SalesTaxReportController::class, 'exportAmendment'])
        ->name('sales-tax.filings.amendment');

    // Purchasing: rental orders (capital equipment) and sales orders (resale
    // stock) are separate series, with receipts and backorders on both.
    Route::prefix('purchase-orders')->name('purchase-orders.')->group(function () {
        Route::get('/', [\App\Http\Controllers\PurchaseOrderController::class, 'index'])->name('index');
        Route::get('/create', [\App\Http\Controllers\PurchaseOrderController::class, 'create'])->name('create');
        Route::get('/backorders', [\App\Http\Controllers\PurchaseOrderController::class, 'backorders'])->name('backorders');
        Route::post('/', [\App\Http\Controllers\PurchaseOrderController::class, 'store'])->name('store');
        Route::get('/{purchase_order}', [\App\Http\Controllers\PurchaseOrderController::class, 'show'])->name('show');
        Route::post('/{purchase_order}/items', [\App\Http\Controllers\PurchaseOrderController::class, 'storeItem'])->name('items.store');
        Route::delete('/{purchase_order}/items/{item}', [\App\Http\Controllers\PurchaseOrderController::class, 'destroyItem'])->name('items.destroy');
        Route::post('/{purchase_order}/receive', [\App\Http\Controllers\PurchaseOrderController::class, 'receive'])->name('receive');
        Route::patch('/{purchase_order}/cancel', [\App\Http\Controllers\PurchaseOrderController::class, 'cancel'])->name('cancel');
    });
});

// Tenant business details: the values contract placeholders are filled from.
Route::middleware(['auth', 'role:admin,manager'])->prefix('business')->name('business.')->group(function () {
    Route::get('/', [\App\Http\Controllers\BusinessProfileController::class, 'edit'])->name('edit');
    Route::put('/', [\App\Http\Controllers\BusinessProfileController::class, 'update'])->name('update');
});

// Tenant branding: each tenant customizes its own brand colors.
Route::middleware(['auth', 'role:admin,manager'])->prefix('theme')->name('theme.')->group(function () {
    Route::get('/', [\App\Http\Controllers\ThemeController::class, 'edit'])->name('edit');
    Route::put('/', [\App\Http\Controllers\ThemeController::class, 'update'])->name('update');
    Route::post('/reset', [\App\Http\Controllers\ThemeController::class, 'reset'])->name('reset');
});

// Rental contract template: global boilerplate + per-tenant customized copy.
Route::middleware(['auth', 'role:super_admin,admin,manager'])->prefix('contract-template')->name('contract-template.')->group(function () {
    Route::get('/', [ContractTemplateController::class, 'show'])->name('show');
    Route::get('/edit', [ContractTemplateController::class, 'edit'])->name('edit');
    Route::put('/', [ContractTemplateController::class, 'update'])->name('update');
});

// Tenant management (super admin only)
Route::middleware(['auth', 'role:super_admin'])->prefix('tenants')->name('tenants.')->group(function () {
    Route::get('/', [TenantController::class, 'index'])->name('index');
    Route::get('/create', [TenantController::class, 'create'])->name('create');
    Route::post('/', [TenantController::class, 'store'])->name('store');
    Route::get('/{tenant}/edit', [TenantController::class, 'edit'])->name('edit');
    Route::put('/{tenant}', [TenantController::class, 'update'])->name('update');
    Route::get('/{tenant}/users/create', [TenantController::class, 'createUser'])->name('users.create');
    Route::post('/{tenant}/users', [TenantController::class, 'storeUser'])->name('users.store');
    Route::patch('/{tenant}/users/{user}/password', [TenantController::class, 'resetUserPassword'])->name('users.password');
    Route::patch('/{tenant}/users/{user}/toggle', [TenantController::class, 'toggleUser'])->name('users.toggle');
    Route::patch('/{tenant}/toggle', [TenantController::class, 'toggle'])->name('toggle');
    Route::post('/{tenant}/impersonate', [TenantController::class, 'impersonate'])->name('impersonate');
    Route::get('/audit-logs', [TenantController::class, 'auditLogs'])->name('audit-logs');
    Route::get('/audit-logs/export', [TenantController::class, 'exportAuditLogs'])->name('audit-logs.export');
});

// Leaving an impersonation session: any authenticated user (super admin came in,
// their elevated rights are restored on the way out).
Route::post('/stop-impersonating', [TenantController::class, 'stopImpersonating'])->name('stop-impersonating')->middleware('auth');

require __DIR__.'/auth.php';

