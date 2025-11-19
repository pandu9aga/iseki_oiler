@extends('layouts.app')

@section('content')
<h2 class="text-primary">Scan Unit Oiler</h2>
<hr>

<div class="row mx-4">
    <div class="col-md-6">
        <!-- Bagian Input dan Scan (Awalnya Tampil) -->
        <div id="scanSection">
            <div class="mb-3">
                <label for="sequence_no" class="form-label">Sequence Number</label>
                <input type="text" class="form-control" id="sequence_no" readonly>
                <button type="button" class="btn btn-primary mt-2" id="scanButton">Scan QR</button>
            </div>

            <!-- Area untuk scanner QR -->
            <div id="reader" style="width: 100%; display: none;"></div>

            <!-- Notifikasi Error -->
            <div id="validation-error-message" class="alert alert-danger mt-3" style="display: none;">
                <strong id="validation-error-text"></strong>
            </div>

            <!-- Loading Indicator -->
            <div id="loadingIndicator" class="spinner-border text-primary mt-3" role="status" style="display: none;">
                <span class="visually-hidden">Processing...</span>
            </div>
        </div>

        <!-- Bagian Detail dan Timer (Awalnya Sembunyi) -->
        <div id="detailSection" style="display: none;">
            <h5>Detail Proses Oiler</h5>
            <div class="mb-2">
                Sequence No: <span id="detailSequenceNo"></span>
            </div>
            <div class="mb-2">
                Scan Time: <span id="scanTimeDisplay"></span>
            </div>
            <div class="mb-3">
                Detect Time: <span id="detectTimeDisplay">-</span> <!-- Default ke '-' -->
            </div>

            <div class="alert alert-info">
                <strong>Timer:</strong> <span id="timerDisplay">40</span>s
            </div>

            <button type="button" class="btn btn-secondary" id="cancelAndResetBtn">Cancel & Reset</button>
        </div>
    </div>
</div>

<!-- Modal Besar untuk Notifikasi "Oli Terdeteksi" -->
<div class="modal fade" id="oilDetectedModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen">
        <div class="modal-content bg-white">
            <div class="modal-body d-flex align-items-center justify-content-center">
                <div class="text-center">
                    <h1 class="text-primary">Oli Terdeteksi</h1>
                    <p class="fs-4">Proses oli berhasil tercatat.</p>
                    <button type="button" 
                            class="btn btn-success" 
                            style="padding: 50px 80px; font-size: 3rem; border-radius: 20px;" 
                            data-bs-dismiss="modal">
                        OK
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

@section('style')
<meta name="csrf-token" content="{{ csrf_token() }}">
@endsection

@section('scripts')
<script src="{{ asset('assets/js/html5-qrcode.min.js') }}"></script>
<script>
    let html5QrcodeScanner;
    const sequenceInput = document.getElementById('sequence_no');
    const readerElement = document.getElementById('reader');
    const validationMessageDiv = document.getElementById('validation-error-message');
    const validationMessageText = document.getElementById('validation-error-text');
    const loadingIndicator = document.getElementById('loadingIndicator');
    const scanSection = document.getElementById('scanSection');
    const detailSection = document.getElementById('detailSection');
    const detailSequenceNoElement = document.getElementById('detailSequenceNo');
    const scanTimeDisplayElement = document.getElementById('scanTimeDisplay');
    const detectTimeDisplayElement = document.getElementById('detectTimeDisplay');
    const timerDisplayElement = document.getElementById('timerDisplay');
    const cancelAndResetBtn = document.getElementById('cancelAndResetBtn');
    const oilDetectedModal = new bootstrap.Modal(document.getElementById('oilDetectedModal'));

    let pollInterval;
    let countdownInterval;
    let countdownTime = 40; // Detik

    // oilDetectedModal.show();

    document.getElementById('scanButton').addEventListener('click', function () {
        if (html5QrcodeScanner) {
            html5QrcodeScanner.clear().then(() => {
                html5QrcodeScanner = null;
                readerElement.style.display = 'none';
            }).catch(console.error);
            return;
        }

        readerElement.style.display = 'block';

        html5QrcodeScanner = new Html5QrcodeScanner(
            "reader", {
                fps: 10,
                qrbox: { width: 250, height: 250 },
            }
        );

        function onScanSuccess(decodedText, decodedResult) {
            const parts = decodedText.split(';');
            let seqNo = parts[0]?.trim() || '';
            seqNo = seqNo.padStart(5, '0');

            sequenceInput.value = seqNo;

            readerElement.style.display = 'none';
            html5QrcodeScanner.clear().catch(console.error);
            html5QrcodeScanner = null;

            processSequence(seqNo);
        }

        html5QrcodeScanner.render(onScanSuccess);
    });

    function processSequence(sequenceNo) {
        validationMessageDiv.style.display = 'none';
        loadingIndicator.style.display = 'block';

        fetch('{{ route("oiler.store") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            },
            body: JSON.stringify({ sequence_no: sequenceNo }),
        })
        .then(response => response.json())
        .then(data => {
            loadingIndicator.style.display = 'none';

            if (data.success) {
                console.log('Proses Oiler sukses:', data.message);
                // Sembunyikan bagian scan
                scanSection.style.display = 'none';
                // Tampilkan bagian detail
                detailSection.style.display = 'block';

                // Isi detail
                detailSequenceNoElement.textContent = sequenceNo;
                // Ambil waktu saat ini untuk Scan Time (karena waktu sebenarnya ditentukan di server)
                const now = new Date().toLocaleString('id-ID', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
                scanTimeDisplayElement.textContent = now; // Tampilkan waktu klien sebagai estimasi
                detectTimeDisplayElement.textContent = '-'; // Default ke '-'

                // Mulai timer 40 detik
                startCountdown(sequenceNo);

            } else {
                console.error('Proses Oiler gagal:', data.message);
                validationMessageText.textContent = data.message;
                validationMessageDiv.style.display = 'block';
            }
        })
        .catch(error => {
            loadingIndicator.style.display = 'none';
            console.error('Error proses Oiler:', error);
            validationMessageText.textContent = 'Gagal menghubungi server.';
            validationMessageDiv.style.display = 'block';
        });
    }

    function startCountdown(sequenceNo) {
        countdownTime = 40;
        timerDisplayElement.textContent = countdownTime;

        // Mulai interval polling setiap 2 detik
        pollInterval = setInterval(() => {
            checkDetectTime(sequenceNo);
        }, 2000);

        // Mulai countdown timer
        countdownInterval = setInterval(() => {
            countdownTime--;
            timerDisplayElement.textContent = countdownTime;

            if (countdownTime <= 0) {
                clearInterval(countdownInterval);
                clearInterval(pollInterval);
                // Hapus data record
                deleteRecord(sequenceNo);
            }
        }, 1000);
    }

    function checkDetectTime(sequenceNo) {
        // Panggil API untuk cek apakah Detect_Time_Record sudah terisi
        fetch(`/iseki_oiler/public/api/check-detect-time/${sequenceNo}`, {
            method: 'GET',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            },
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.detect_time) {
                // Jika detect_time ditemukan, hentikan timer
                clearInterval(countdownInterval);
                clearInterval(pollInterval);

                // Update tampilan Detect Time
                detectTimeDisplayElement.textContent = data.detect_time;

                // Tampilkan modal "Oli Terdeteksi"
                oilDetectedModal.show();
            }
        })
        .catch(error => {
            console.error('Error saat mengecek Detect_Time:', error);
            // Jangan hentikan timer jika hanya gagal request, mungkin server sibuk
        });
    }

    function deleteRecord(sequenceNo) {
        // Panggil API untuk menghapus record berdasarkan Sequence_No_Record
        fetch(`/iseki_oiler/public/api/delete-record/${sequenceNo}`, {
            method: 'DELETE', // Gunakan method DELETE
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            },
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Record dihapus karena timeout:', sequenceNo);
                // Reset tampilan ke awal
                resetForm();
            } else {
                console.error('Gagal menghapus record:', data.message);
                // Tetap reset form agar user bisa scan lagi, tapi log error
                resetForm();
            }
        })
        .catch(error => {
            console.error('Error menghapus record:', error);
            // Tetap reset form agar user bisa scan lagi, tapi log error
            resetForm();
        });
    }

    function resetForm() {
        sequenceInput.value = '';
        scanSection.style.display = 'block';
        detailSection.style.display = 'none';
        validationMessageDiv.style.display = 'none';
    }

    // Event listener untuk tombol Cancel & Reset
    cancelAndResetBtn.addEventListener('click', function() {
        // Hentikan timer jika aktif
        if (countdownInterval) clearInterval(countdownInterval);
        if (pollInterval) clearInterval(pollInterval);
        // Hapus record terkait
        const currentSequenceNo = detailSequenceNoElement.textContent;
        if (currentSequenceNo) {
            deleteRecord(currentSequenceNo);
        }
    });

    // Event listener untuk saat modal ditutup
    document.getElementById('oilDetectedModal').addEventListener('hidden.bs.modal', function () {
        // Reset form setelah modal ditutup
        resetForm();
    });

</script>
@endsection