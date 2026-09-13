<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/privacy-policy', 'privacy-policy')->name('privacy-policy');
