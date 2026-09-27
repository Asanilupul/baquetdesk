<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\RestQueryController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\VendorInviteController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1');
Route::post('/auth/logout', [AuthController::class, 'logout'])
    ->middleware('throttle:30,1');

Route::post('/db/query', [RestQueryController::class, 'handle'])
    ->middleware('throttle:120,1');
Route::match(['get', 'post'], '/db/bootstrap', [RestQueryController::class, 'bootstrap'])
    ->middleware('throttle:60,1');

Route::post('/company/register', [CompanyController::class, 'register'])
    ->middleware('throttle:5,1');
Route::get('/company/{id}', [CompanyController::class, 'show'])
    ->middleware('throttle:60,1');
Route::post('/company/{id}', [CompanyController::class, 'update'])
    ->middleware('throttle:30,1');

Route::post('/su/login', [SuperAdminController::class, 'login'])
    ->middleware('throttle:5,1');
Route::get('/su/companies', [SuperAdminController::class, 'companies'])
    ->middleware('throttle:60,1');
Route::post('/su/companies/{id}/subscription', [SuperAdminController::class, 'updateSubscription'])
    ->middleware('throttle:30,1');
Route::post('/su/companies/{id}/status', [SuperAdminController::class, 'setCompanyStatus'])
    ->middleware('throttle:30,1');
Route::post('/su/change-password', [SuperAdminController::class, 'changePassword'])
    ->middleware('throttle:5,1');
Route::get('/su/vendors', [SuperAdminController::class, 'vendors'])
    ->middleware('throttle:60,1');
Route::post('/su/vendor-invites', [SuperAdminController::class, 'createVendorInvites'])
    ->middleware('throttle:30,1');

Route::get('/vendor-invites', [VendorInviteController::class, 'index'])
    ->middleware('throttle:60,1');
Route::post('/vendor-invites', [VendorInviteController::class, 'store'])
    ->middleware('throttle:30,1');
Route::post('/vendor-invites/{id}/revoke', [VendorInviteController::class, 'revoke'])
    ->middleware('throttle:30,1');
Route::get('/vendor-invites/check', [VendorInviteController::class, 'check'])
    ->middleware('throttle:30,1');
Route::post('/vendor-invites/register', [VendorInviteController::class, 'register'])
    ->middleware('throttle:10,1');

Route::get('/audit-logs', [AuditLogController::class, 'index'])
    ->middleware('throttle:60,1');

Route::get('/backup/status', [BackupController::class, 'status'])
    ->middleware('throttle:60,1');
Route::post('/backup/settings', [BackupController::class, 'saveSettings'])
    ->middleware('throttle:20,1');
Route::post('/backup/run', [BackupController::class, 'runNow'])
    ->middleware('throttle:10,1');
Route::get('/backup/download', [BackupController::class, 'download'])
    ->middleware('throttle:10,1');
Route::post('/backup/restore', [BackupController::class, 'restore'])
    ->middleware('throttle:3,1');
