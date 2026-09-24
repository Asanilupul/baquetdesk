<?php

use App\Http\Controllers\PublicMenuController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->file(public_path('banquetdesk.html'));
});

Route::get('/su-admin', function () {
    return response()->file(public_path('su-admin.html'));
});

Route::get('/menus/{token}', [PublicMenuController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('public-menus.show');
