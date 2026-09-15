<?php

use App\Http\Controllers\RestQueryController;
use Illuminate\Support\Facades\Route;

Route::post('/db/query', [RestQueryController::class, 'handle']);
