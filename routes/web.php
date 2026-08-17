<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ShopifyOAuthController;
use App\Http\Controllers\ShopifyWebhookController;
use App\Http\Controllers\DashboardController;

// Shopify OAuth Installation Flow
Route::get('/shopify/auth', [ShopifyOAuthController::class, 'auth'])->name('shopify.auth');
Route::get('/shopify/callback', [ShopifyOAuthController::class, 'callback'])->name('shopify.callback');

// Shopify Webhook Endpoint (CSRF-exempted in bootstrap/app.php + protected by signature middleware)
Route::middleware('shopify.webhook')->group(function () {
    Route::post('/api/webhooks', [ShopifyWebhookController::class, 'handle'])->name('shopify.webhook');
    Route::post('/api/webhooks/customers/data_request', [ShopifyWebhookController::class, 'customersDataRequest'])->name('shopify.webhook.customers_data_request');
    Route::post('/api/webhooks/customers/redact', [ShopifyWebhookController::class, 'customersRedact'])->name('shopify.webhook.customers_redact');
    Route::post('/api/webhooks/shop/redact', [ShopifyWebhookController::class, 'shopRedact'])->name('shopify.webhook.shop_redact');
});

// Central Dashboard & Admin API Routes (protected by shopify session middleware)
Route::middleware('shopify.session')->group(function () {
    Route::get('/dashboard', \App\Livewire\Dashboard::class)->name('dashboard');
    Route::post('/dashboard/products/{id}/update-upi', [DashboardController::class, 'updateUpi'])->name('dashboard.update_upi');
    Route::post('/dashboard/import-csv', [DashboardController::class, 'importCsv'])->name('dashboard.import_csv');
    Route::get('/dashboard/export-csv', [DashboardController::class, 'exportCsv'])->name('dashboard.export_csv');
    Route::get('/dashboard/licensing-export', [DashboardController::class, 'licensingExport'])->name('dashboard.licensing_export');
    Route::get('/dashboard/job-status/{job_id}', [DashboardController::class, 'jobStatus'])->name('dashboard.job_status');
    Route::get('/dashboard/download-export/{job_id}', [DashboardController::class, 'downloadExport'])->name('dashboard.download_export');
    
    // REST API Module Endpoints
    Route::get('/api/products/{id}/upi', [\App\Http\Controllers\UpiApiController::class, 'show'])->name('api.upi.show');
    Route::post('/api/products/{id}/upi', [\App\Http\Controllers\UpiApiController::class, 'store'])->name('api.upi.store');
    Route::delete('/api/products/{id}/upi', [\App\Http\Controllers\UpiApiController::class, 'destroy'])->name('api.upi.destroy');
    Route::post('/api/products/upi/bulk', [\App\Http\Controllers\UpiApiController::class, 'bulk'])->name('api.upi.bulk');
});

// Redirect obsolete admin routes to the new Filament Admin panel
Route::redirect('/admin/dashboard', '/admin');

// Fallback home page redirecting to dashboard or install
Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Privacy Policy (publicly accessible)
Route::get('/privacy-policy', function () {
    return view('privacy-policy');
})->name('privacy_policy');

