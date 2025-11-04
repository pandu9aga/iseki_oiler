<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OilerController;

Route::get('/', [OilerController::class, 'index'])->name('home');
Route::get('/scan', [OilerController::class, 'showScan'])->name('oiler.scan');
Route::post('/scan', [OilerController::class, 'store'])->name('oiler.store'); // Gunakan ini untuk scan dan proses
Route::get('/list', [OilerController::class, 'list'])->name('oiler.list');
