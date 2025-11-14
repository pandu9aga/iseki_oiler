<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OilerController;

Route::get('/records', [OilerController::class, 'getRecords'])->name('oiler.records.data');
Route::post('/nodemcu/status', [OilerController::class, 'handleNodeMCUStatus'])->name('oiler.nodemcu.status');

// Route untuk cek Detect_Time_Record berdasarkan Sequence_No_Record
Route::get('/check-detect-time/{sequence_no}', [OilerController::class, 'checkDetectTime'])->name('api.oiler.check_detect_time');
// Route untuk hapus record berdasarkan Sequence_No_Record
Route::delete('/delete-record/{sequence_no}', [OilerController::class, 'deleteRecord'])->name('api.oiler.delete_record');