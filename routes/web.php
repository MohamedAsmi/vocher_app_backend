<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard/login', [App\Http\Controllers\DashboardController::class, 'loginForm'])->name('dashboard.login');
Route::post('/dashboard/login', [App\Http\Controllers\DashboardController::class, 'login'])->name('dashboard.login.store');
Route::get('/dashboard', [App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');
Route::post('/dashboard/users/{user}/password', [App\Http\Controllers\DashboardController::class, 'updatePassword'])->name('dashboard.users.password');
Route::post('/dashboard/users/{user}/verify-password', [App\Http\Controllers\DashboardController::class, 'verifyPassword'])->name('dashboard.users.verify-password');
Route::post('/dashboard/logout', [App\Http\Controllers\DashboardController::class, 'logout'])->name('dashboard.logout');

Route::view('/privacy-policy', 'privacy-policy')->name('privacy-policy');
