<?php

namespace App\Http\Controllers;

use App\Models\Record;
use App\Models\Notification;
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

        // Format sequence_no ke 5 digit (jika perlu, sesuaikan dengan format di podium)
        $formattedSequenceNo = str_pad($sequenceNo, 5, '0', STR_PAD_LEFT);

        // --- LOGIKA VALIDASI AWAL DI SISTEM OILER ---
        // Cek di tabel records apakah ada entry dengan Detect_Time_Record NULL
        $incompleteRecord = DB::table('records')
            ->select('Sequence_No_Record') // Ambil kolom Sequence_No_Record
            ->whereNull('Detect_Time_Record')
            ->first(); // Ambil satu record saja

        if ($incompleteRecord) {
            $incompleteSequenceNo = $incompleteRecord->Sequence_No_Record;
            return response()->json([
                'success' => false,
                'message' => "Nomor scan {$incompleteSequenceNo} sebelumnya belum mendeteksi oli."
            ], 400); // Gunakan 400 Bad Request untuk error validasi
        }

        // --- LOGIKA VALIDASI TERHADAP SISTEM PODIUM ---
        $processName = 'oiler'; // Nama proses oiler
        $timestamp = Carbon::now()->format('Y-m-d H:i:s'); // Timestamp saat proses ini dicatat (jika perlu)

        try {
            // 1. Cari plan di database PODIUM berdasarkan Sequence_No_Plan
            $plan = DB::connection('podium')->table('plans')->where('Sequence_No_Plan', $formattedSequenceNo)->first();
            if (!$plan) {
                return response()->json([
                    'success' => false,
                    'message' => "Plan dengan Sequence_No_Plan '{$formattedSequenceNo}' tidak ditemukan di sistem PODIUM."
                ], 404);
            }

            $modelName = $plan->Model_Name_Plan;

            // 2. Cari rule di database PODIUM berdasarkan Type_Rule
            $rule = DB::connection('podium')->table('rules')->where('Type_Rule', $modelName)->first();
            if (!$rule) {
                return response()->json([
                    'success' => false,
                    'message' => "Rule untuk model '{$modelName}' tidak ditemukan di sistem PODIUM."
                ], 400);
            }

            // 3. Ambil Rule_Rule (ini berupa string JSON dari Query Builder)
            $ruleSequenceRaw = $rule->Rule_Rule;

            // Coba decode string JSON menjadi array
            $ruleSequence = null;
            if (is_string($ruleSequenceRaw)) {
                $ruleSequence = json_decode($ruleSequenceRaw, true); // true untuk mengembalikan array asosiatif
            }

            // Pastikan $ruleSequence adalah array hasil decode JSON.
            if (!is_array($ruleSequence)) {
                // Jika decode gagal atau nilainya bukan string JSON valid, kembalikan error
                Log::error("Rule_Rule untuk model '{$modelName}' bukan string JSON valid.", [
                    'raw_value' => $ruleSequenceRaw,
                    'decoded_value' => $ruleSequence,
                    'decoded_type' => gettype($ruleSequence)
                ]);
                return response()->json([
                    'success' => false,
                    'message' => "Format rule untuk model '{$modelName}' rusak atau tidak valid."
                ], 400);
            }

            // 4. Cek apakah process_name ('oiler') ada dalam rule
            $position = null;
            $found = false;
            foreach ($ruleSequence as $key => $process) {
                if ($process === $processName) {
                    $position = (int)$key;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                return response()->json([
                    'success' => false,
                    'message' => "Proses Oiler tidak termasuk dalam rule untuk model '{$modelName}'."
                ], 400);
            }

            // 5. Ambil Record_Plan (ini berupa string JSON dari Query Builder)
            $recordPlanRaw = $plan->Record_Plan;

            // Coba decode string JSON menjadi array
            $recordPlan = [];
            if (is_string($recordPlanRaw) && !empty($recordPlanRaw)) {
                $decodedRecord = json_decode($recordPlanRaw, true);
                if (is_array($decodedRecord)) {
                    $recordPlan = $decodedRecord;
                } else {
                    // Jika decode gagal atau nilainya bukan string JSON valid, kembalikan error
                    Log::error("Record_Plan untuk Id_Plan {$plan->Id_Plan} bukan string JSON valid.", [
                        'raw_value' => $recordPlanRaw,
                        'decoded_value' => $decodedRecord,
                        'decoded_type' => gettype($decodedRecord)
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => "Format Record_Plan untuk plan ini rusak."
                    ], 500); // atau 400
                }
            } // Jika null atau kosong, biarkan $recordPlan sebagai array kosong

            // 6. Cek apakah proses sebelumnya sudah dilakukan sesuai urutan rule
            $previousProcessesDone = true;
            $missingPrevious = [];
            // Loop dari 1 hingga posisi proses 'oiler' - 1
            for ($i = 1; $i < $position; $i++) {
                $prevProcessName = $ruleSequence[(string)$i] ?? null; // Gunakan (string)$i karena key JSON adalah string
                if ($prevProcessName !== null && !isset($recordPlan[$prevProcessName])) {
                    $previousProcessesDone = false;
                    $missingPrevious[] = $prevProcessName;
                }
            }

            if (!$previousProcessesDone) {
                return response()->json([
                    'success' => false,
                    'message' => "Proses sebelumnya belum selesai: " . implode(', ', $missingPrevious)
                ], 400);
            }

            // --- LOGIKA PENYIMPANAN KE DATABASE OILER (Jika semua validasi lolos) ---
            $scanTime = Carbon::now();
            // $detectTime = null; // Kita tetap gunakan null seperti sebelumnya

            $recordData = [
                'Sequence_No_Record' => $formattedSequenceNo,
                'Scan_Time_Record' => $scanTime,
                'Detect_Time_Record' => null, // Selalu null saat insert awal
                // 'Photo_Path' bisa ditambahkan jika nanti fitur upload foto ditambahkan
            ];

            // Simpan ke tabel records di database sistem Oiler
            $newRecord = Record::create($recordData);

            // Kembalikan respons sukses
            return response()->json([
                'success' => true,
                'message' => "Proses Oiler untuk sequence {$formattedSequenceNo} berhasil dicatat.",
                'data' => $newRecord // Opsional: kembalikan data record yang baru dibuat
            ]);

        } catch (\Exception $e) {
            // Tangani exception umum selama proses validasi dan penyimpanan ke PODIUM/OILER
            Log::error('Gagal memproses di sistem Oiler atau PODIUM: ' . $e->getMessage(), [
                'sequence_no' => $sequenceNo,
                'exception' => $e
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses di sistem Oiler atau PODIUM: ' . $e->getMessage()
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
            $currentRecordPlanArray = [];
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
            } // Jika null atau kosong, biarkan $currentRecordPlanArray sebagai array kosong

            // 6. Tambahkan atau update proses "oiler" di array
            $processName = 'oiler'; // Nama proses sesuai rule
            $currentRecordPlanArray[$processName] = $oilerTimestamp; // Misal: ["oiler" => "2025-11-04 10:00:00"]

            // --- LOGIKA TAMBAHAN: Cek apakah SEMUA proses dalam rule untuk model ini sekarang sudah selesai ---
            $allProcessesCompleted = true;
            $processesMissing = []; // Untuk logging/debugging jika perlu

            // Ambil rule berdasarkan Model_Name_Plan dari plan
            $modelName = $plan->Model_Name_Plan;
            $rule = DB::connection('podium')->table('rules')->where('Type_Rule', $modelName)->first();

            if ($rule) {
                $ruleSequenceRaw = $rule->Rule_Rule;
                $ruleSequence = null;
                if (is_string($ruleSequenceRaw)) {
                    $ruleSequence = json_decode($ruleSequenceRaw, true);
                }

                if (is_array($ruleSequence)) {
                    // Iterasi setiap proses yang DIDEFINISIKAN dalam rule
                    foreach ($ruleSequence as $expectedProcessName) {
                        // Cek apakah proses yang DIDEFINISIKAN ada di Record_Plan
                        if (!isset($currentRecordPlanArray[$expectedProcessName])) {
                            $allProcessesCompleted = false;
                            $processesMissing[] = $expectedProcessName; // Tambahkan ke daftar yang belum selesai
                            // Tidak perlu break, kita ingin tahu semua yang belum selesai jika perlu log
                        }
                    }
                } else {
                    // Jika rule tidak valid, kita asumsikan statusnya tidak bisa ditentukan sebagai 'done'
                    $allProcessesCompleted = false;
                    // Log opsional: \Log::warning("Rule untuk model {$modelName} tidak valid saat update NodeMCU.", ['raw_rule' => $ruleSequenceRaw]);
                }
            } else {
                // Jika rule tidak ditemukan, kita asumsikan statusnya tidak bisa ditentukan sebagai 'done'
                $allProcessesCompleted = false;
                // Log opsional: \Log::warning("Rule tidak ditemukan untuk model {$modelName} saat update NodeMCU.");
            }

            // 7. Encode array kembali menjadi string JSON
            $updatedRecordPlanJson = json_encode($currentRecordPlanArray, JSON_UNESCAPED_UNICODE);

            // 8. Update Record_Plan di database PODIUM
            DB::connection('podium')->table('plans')
                ->where('Id_Plan', $plan->Id_Plan) // Gunakan Id_Plan untuk update yang akurat
                ->update(['Record_Plan' => $updatedRecordPlanJson]);

            // --- LOGIKA UPDATE STATUS PLAN BERDASARKAN CEK ---
            if ($allProcessesCompleted) {
                // Update Status_Plan ke 'done'
                DB::connection('podium')->table('plans')
                    ->where('Id_Plan', $plan->Id_Plan)
                    ->update(['Status_Plan' => 'done']);

                // Log opsional: \Log::info("Status_Plan diupdate menjadi 'done' untuk Id_Plan: {$plan->Id_Plan} karena semua proses selesai setelah menambahkan 'oiler'.");
            } else {
                // Opsional: Log proses yang masih belum selesai
                // \Log::debug("Status_Plan tidak diupdate untuk Id_Plan: {$plan->Id_Plan}. Proses yang belum selesai: " . implode(', ', $processesMissing));
            }

            // 9. Update Detect_Time_Record di database OILER
            $recordToUpdate->Detect_Time_Record = $oilerTimestamp;
            $recordToUpdate->save(); // Simpan perubahan

            // 10. Kembalikan response sukses ke NodeMCU
            $statusMessage = $allProcessesCompleted ? " dan Status Plan: Done." : " dan Status Plan: Pending (masih ada proses yang belum selesai).";
            return response()->json([
                'success' => true,
                'message' => 'Detect time updated successfully for sequence: ' . $sequenceNoToUpdate . ' (Scanned at: ' . $recordToUpdate->Scan_Time_Record . ')' . $statusMessage,
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

    // Method untuk cek Detect_Time_Record
    public function checkDetectTime($sequenceNo)
    {
        // Format sequence_no ke 5 digit
        $formattedSequenceNo = str_pad($sequenceNo, 5, '0', STR_PAD_LEFT);

        // Cari record berdasarkan Sequence_No_Record
        $record = Record::where('Sequence_No_Record', $formattedSequenceNo)->first();

        if ($record) {
            if ($record->Detect_Time_Record) {
                // Jika Detect_Time_Record tidak null, kirimkan nilainya
                return response()->json([
                    'success' => true,
                    'detect_time' => $record->Detect_Time_Record // Format sesuai kebutuhan frontend
                ]);
            } else {
                // Jika Detect_Time_Record masih null
                return response()->json([
                    'success' => true,
                    'detect_time' => null
                ]);
            }
        } else {
            // Jika record tidak ditemukan
            return response()->json([
                'success' => false,
                'message' => 'Record tidak ditemukan.'
            ], 404);
        }
    }

    // Method untuk hapus record berdasarkan Sequence_No_Record
    public function deleteRecord($sequenceNo)
    {
        // Format sequence_no ke 5 digit
        $formattedSequenceNo = str_pad($sequenceNo, 5, '0', STR_PAD_LEFT);

        // Cari record dan hapus
        $deletedRows = Record::where('Sequence_No_Record', $formattedSequenceNo)->delete();

        if ($deletedRows > 0) {
            return response()->json([
                'success' => true,
                'message' => 'Record berhasil dihapus.'
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Record tidak ditemukan atau gagal dihapus.'
            ], 404);
        }
    }

}