<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->file(public_path('banquetdesk.html'));
});

Route::get('/su-admin', function () {
    return response()->file(public_path('su-admin.html'));
});
