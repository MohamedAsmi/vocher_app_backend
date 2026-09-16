<?php

use App\Http\Controllers\Api\AdminConfigurationController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookkeeperController;
use App\Http\Controllers\Api\VoucherController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::delete('/logout', [AuthController::class, 'logout']);
    Route::get('/organizations/{org}/expense-categories', [VoucherController::class, 'categories']);
    Route::post('/organizations/{org}/outlets/{outlet}/vouchers/today', [VoucherController::class, 'today']);
    Route::put('/organizations/{org}/vouchers/{voucher}', [VoucherController::class, 'update']);
    Route::post('/organizations/{org}/vouchers/{voucher}/submit', [VoucherController::class, 'submitForReview']);
    Route::get('/organizations/{org}/outlets/{outlet}/vouchers', [VoucherController::class, 'history']);
    Route::post('/organizations/{org}/vouchers/{voucher}/expenses/{expense}/receipt', [VoucherController::class, 'receipt']);
    Route::delete('/organizations/{org}/receipts', [VoucherController::class, 'deleteReceipt']);
    Route::get('/organizations/{org}/bookkeeper/reviews', [BookkeeperController::class, 'reviews']);
    Route::post('/organizations/{org}/bookkeeper/vouchers/{voucher}/approve', [BookkeeperController::class, 'approve']);
    Route::put('/organizations/{org}/follow-ups/{voucher}', [BookkeeperController::class, 'followUp']);
    Route::get('/organizations/{org}/admin/users', [AdminUserController::class, 'index']);
    Route::post('/organizations/{org}/admin/users', [AdminUserController::class, 'store']);
    Route::put('/organizations/{org}/admin/users/{user}', [AdminUserController::class, 'update']);
    Route::delete('/organizations/{org}/admin/users/{user}', [AdminUserController::class, 'destroy']);
    Route::get('/organizations/{org}/admin/configuration', [AdminConfigurationController::class, 'index']);
    Route::post('/organizations/{org}/admin/categories', [AdminConfigurationController::class, 'storeCategory']);
    Route::put('/organizations/{org}/admin/categories/{category}', [AdminConfigurationController::class, 'updateCategory']);
    Route::post('/organizations/{org}/admin/outlets', [AdminConfigurationController::class, 'storeOutlet']);
    Route::put('/organizations/{org}/admin/outlets/{outlet}', [AdminConfigurationController::class, 'updateOutlet']);
});
