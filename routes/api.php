<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\RestQueryController;
use Illuminate\Support\Facades\Route;

Route::post('/db/query', [RestQueryController::class, 'handle']);
Route::match(['get', 'post'], '/db/bootstrap', [RestQueryController::class, 'bootstrap']);

Route::post('/company/register', [CompanyController::class, 'register']);
Route::get('/company/{id}', [CompanyController::class, 'show']);
Route::post('/company/{id}', [CompanyController::class, 'update']);

Route::get('/backup/status', [BackupController::class, 'status']);
Route::post('/backup/settings', [BackupController::class, 'saveSettings']);
Route::post('/backup/run', [BackupController::class, 'runNow']);
Route::get('/backup/download', [BackupController::class, 'download']);
Route::post('/backup/restore', [BackupController::class, 'restore']);
