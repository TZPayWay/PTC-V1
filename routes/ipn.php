<?php

use Illuminate\Support\Facades\Route;

// TZPayWay Webhook / IPN Routes
Route::post('TZPAYWAY', 'TZPAYWAY\ProcessController@ipn')->name('TZPAYWAY');
Route::post('tzpayway', 'TZPAYWAY\ProcessController@ipn')->name('tzpayway');
