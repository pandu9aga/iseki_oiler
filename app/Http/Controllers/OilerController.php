<?php

namespace App\Http\Controllers;

use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class OilerController extends Controller
{
    public function index()
    {
        return redirect()->route('oiler.scan');
    }

    public function showScan()
    {
        return view('oiler.scan');
    }

    public function list()
    {
        return view('oiler.list');
    }

    public function getRecords(Request $request) // Untuk DataTables API
    {
        $query = Record::select('*')
                  ->orderBy('Scan_Time_Record', 'desc'); // 'desc' untuk terbaru dulu

        return datatables()->of($query)
            ->make(true);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sequence_no' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 400);
        }

        $sequenceNo = $request->input('sequence_no');
        $processName = 'oiler';

        // Format sequence_no ke 5 digit (jika perlu, sesuaikan dengan format di podium)
        $formattedSequenceNo = str_pad($sequenceNo, 5, '0', STR_PAD_LEFT);

        // --- LOGIKA VALIDASI (digabung dari validateSequence) ---
        // Cek di tabel records apakah ada entry dengan Detect_Time_Record NULL untuk sequence_no ini
        $existingIncompleteRecord = DB::table('records')
            ->where('Sequence_No_Record', $formattedSequenceNo)
            ->whereNull('Detect_Time_Record')
            ->exists();

        if ($existingIncompleteRecord) {
            return response()->json([
                'success' => false,
                'message' => "Nomor scan {$formattedSequenceNo} sebelumnya belum mendeteksi oli."
            ], 400);
        }

        try {
            // 1. Cari plan di database PODIUM
            $plan = DB::connection('podium')->table('plans')->where('Sequence_No_Plan', $formattedSequenceNo)->first();
            if (!$plan) {
                return response()->json([
                    'success' => false,
                    'message' => "Plan dengan Sequence_No_Plan '{$formattedSequenceNo}' tidak ditemukan di sistem PODIUM."
                ], 404);
            }

            $modelName = $plan->Model_Name_Plan;

            // 2. Cari rule di database PODIUM
            $rule = DB::connection('podium')->table('rules')->where('Type_Rule', $modelName)->first();
            if (!$rule) {
                return response()->json([
                    'success' => false,
                    'message' => "Rule untuk model '{$modelName}' tidak ditemukan di sistem PODIUM."
                ], 400);
            }

            // 3. Ambil Rule_Rule (string JSON dari Query Builder)
            $ruleSequenceRaw = $rule->Rule_Rule;

            // Coba decode string JSON menjadi array
            $ruleSequence = null;
            if (is_string($ruleSequenceRaw)) {
                $ruleSequence = json_decode($ruleSequenceRaw, true); // true untuk mengembalikan array asosiatif
            }

            // Pastikan $ruleSequence adalah array hasil decode JSON.
            if (!is_array($ruleSequence)) {
                return response()->json([
                    'success' => false,
                    'message' => "Format rule untuk model '{$modelName}' rusak atau tidak valid."
                ], 400);
            }

            // 4. Cek apakah process_name (oiler) ada dalam rule
            $position = null;
            foreach ($ruleSequence as $key => $process) {
                if ($process === $processName) {
                    $position = (int)$key;
                    break;
                }
            }

            if ($position === null) {
                return response()->json([
                    'success' => false,
                    'message' => "Proses Oiler tidak termasuk dalam rule untuk model '{$modelName}'."
                ], 400);
            }

            // 5. Ambil Record_Plan (string JSON dari Query Builder)
            $recordRaw = $plan->Record_Plan;

            // Coba decode string JSON menjadi array
            $record = [];
            if (is_string($recordRaw) && !empty($recordRaw)) {
                $decodedRecord = json_decode($recordRaw, true);
                if (is_array($decodedRecord)) {
                    $record = $decodedRecord;
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => "Format Record_Plan untuk plan ini rusak."
                    ], 500); // atau 400
                }
            } // Jika null atau kosong, biarkan $record sebagai array kosong

            // 6. Cek apakah proses sebelumnya sudah dilakukan
            $previousProcessesDone = true;
            $missingPrevious = [];
            for ($i = 1; $i < $position; $i++) {
                $prevProcess = $ruleSequence[$i] ?? null;
                if ($prevProcess && !isset($record[$prevProcess])) {
                    $previousProcessesDone = false;
                    $missingPrevious[] = $prevProcess;
                }
            }

            if (!$previousProcessesDone) {
                return response()->json([
                    'success' => false,
                    'message' => "Proses sebelumnya belum selesai: " . implode(', ', $missingPrevious)
                ], 400);
            }

            // --- LOGIKA PENYIMPANAN (jika validasi sukses) ---
            $scanTime = Carbon::now();
            $detectTime = null; // Selalu null sesuai permintaan

            $recordData = [
                'Sequence_No_Record' => $formattedSequenceNo, // Gunakan yang sudah diformat
                'Scan_Time_Record' => $scanTime,
                'Detect_Time_Record' => $detectTime,
                // 'Photo_Path' => null, // Tidak disimpan karena tidak ada foto
            ];

            // Simpan ke database records (oiler_db)
            Record::create($recordData);

            // Kembalikan respons sukses
            return response()->json([
                'success' => true,
                'message' => "Proses Oiler untuk sequence {$formattedSequenceNo} berhasil dicatat."
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses di sistem PODIUM: ' . $e->getMessage()
            ], 500);
        }
    }

    public function handleNodeMCUStatus(Request $request)
    {
        // 1. Cari record di database OILER yang Detect_Time_Record nya NULL, diurutkan berdasarkan Scan_Time_Record tertua dulu
        $recordToUpdate = Record::whereNull('Detect_Time_Record')
                            ->orderBy('Scan_Time_Record', 'asc') // 'asc' untuk tertua dulu
                            ->first();

        if ($recordToUpdate) {
            // Ambil Sequence_No_Record dari record yang ditemukan
            $sequenceNoToUpdate = $recordToUpdate->Sequence_No_Record; // Misal: "06731"

            // 2. Persiapkan timestamp untuk proses "oiler"
            $oilerTimestamp = Carbon::now()->format('Y-m-d H:i:s'); // Format timestamp

            // 3. Ambil data plan dari database PODIUM berdasarkan Sequence_No_Plan
            // Format sequence_no ke 5 digit (jika perlu, sesuaikan dengan format di podium, contoh: "6731" -> "06731")
            // Kita asumsikan $sequenceNoToUpdate dari record oiler sudah diformat dengan benar (misalnya "06731")
            $formattedSequenceNo = str_pad($sequenceNoToUpdate, 5, '0', STR_PAD_LEFT); // Contoh: "6731" -> "06731"

            $plan = DB::connection('podium')->table('plans')->where('Sequence_No_Plan', $formattedSequenceNo)->first();

            if (!$plan) {
                // Jika plan tidak ditemukan di podium untuk sequence ini, log atau tangani error
                //\Log::warning("Plan tidak ditemukan di PODIUM untuk Sequence_No_Plan: " . $formattedSequenceNo . " saat NodeMCU mengirim status.");
                // Kita tetap update record di oiler, tapi tandai bahwa podium tidak diperbarui
                $recordToUpdate->Detect_Time_Record = $oilerTimestamp;
                $recordToUpdate->save();

                return response()->json([
                    'success' => true, // Dianggap sukses di sisi oiler
                    'message' => 'Detect time updated locally for sequence: ' . $sequenceNoToUpdate . ', but plan not found in PODIUM.',
                    'updated_record' => $recordToUpdate
                ], 200);
            }

            // 4. Ambil Record_Plan saat ini dari plan (ini string JSON)
            $currentRecordPlanJson = $plan->Record_Plan; // Misal: {"parcom_ring_synchronizer":"2025-10-31 14:04:04", ...}

            // 5. Decode JSON menjadi array PHP
            $currentRecordPlanArray = null;
            if (is_string($currentRecordPlanJson) && !empty($currentRecordPlanJson)) {
                $decoded = json_decode($currentRecordPlanJson, true); // true untuk array asosiatif
                if (is_array($decoded)) {
                    $currentRecordPlanArray = $decoded;
                } else {
                    // Jika decode gagal atau hasilnya bukan array, log error dan hentikan update podium
                    // \Log::error("Record_Plan untuk Id_Plan {$plan->Id_Plan} bukan string JSON valid saat update NodeMCU.", [
                    //     'raw_value' => $currentRecordPlanJson,
                    //     'decoded_value' => $decoded,
                    //     'decoded_type' => gettype($decoded)
                    // ]);
                    // Kita tetap update record di oiler, tapi tandai bahwa podium tidak diperbarui karena error parsing
                    $recordToUpdate->Detect_Time_Record = $oilerTimestamp;
                    $recordToUpdate->save();

                    return response()->json([
                        'success' => true, // Dianggap sukses di sisi oiler
                        'message' => 'Detect time updated locally for sequence: ' . $sequenceNoToUpdate . ', but Record_Plan in PODIUM was invalid JSON.',
                        'updated_record' => $recordToUpdate
                    ], 200);
                }
            } else {
                // Jika Record_Plan kosong/null, inisialisasi sebagai array kosong
                $currentRecordPlanArray = [];
            }

            // 6. Tambahkan atau update proses "oiler" di array
            $processName = 'oiler'; // Nama proses sesuai rule
            $currentRecordPlanArray[$processName] = $oilerTimestamp; // Misal: ["oiler" => "2025-11-04 10:00:00"]

            // 7. Encode array kembali menjadi string JSON
            $updatedRecordPlanJson = json_encode($currentRecordPlanArray, JSON_UNESCAPED_UNICODE);

            // 8. Update Record_Plan di database PODIUM
            DB::connection('podium')->table('plans')
                ->where('Id_Plan', $plan->Id_Plan) // Gunakan Id_Plan untuk update yang akurat
                ->update(['Record_Plan' => $updatedRecordPlanJson]);

            // 9. Update Detect_Time_Record di database OILER
            $recordToUpdate->Detect_Time_Record = $oilerTimestamp;
            $recordToUpdate->save(); // Simpan perubahan

            // 10. Kembalikan response sukses ke NodeMCU
            return response()->json([
                'success' => true,
                'message' => 'Detect time updated successfully for sequence: ' . $sequenceNoToUpdate . ' (Scanned at: ' . $recordToUpdate->Scan_Time_Record . ') and PODIUM Record_Plan updated.',
                'updated_record' => $recordToUpdate
            ], 200);
        } else {
            // Jika tidak ada record yang belum terdeteksi (Detect_Time_Record != NULL semua)
            return response()->json([
                'success' => false,
                'message' => 'No pending records found to update Detect_Time_Record.'
            ], 200); // Gunakan 200 OK, bukan 404, karena bukan error di sisi NodeMCU
        }
    }

}