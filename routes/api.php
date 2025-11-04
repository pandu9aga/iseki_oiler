<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OilerController;

Route::get('/records', [OilerController::class, 'getRecords'])->name('oiler.records.data');
Route::post('/nodemcu/status', [OilerController::class, 'handleNodeMCUStatus'])->name('oiler.nodemcu.status');
