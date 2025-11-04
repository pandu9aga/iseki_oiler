@extends('layouts.app')

@section('content')
<h2 class="text-primary">Scan Unit Oiler</h2>
<hr>

<div class="row">
    <div class="col-md-6">
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

        <!-- Loading Indicator (Opsional) -->
        <div id="loadingIndicator" class="spinner-border text-primary mt-3" role="status" style="display: none;">
            <span class="visually-hidden">Processing...</span>
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
        loadingIndicator.style.display = 'block'; // Tampilkan loading

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
            loadingIndicator.style.display = 'none'; // Sembunyikan loading

            if (data.success) {
                // Proses sukses: Redirect ke halaman list
                console.log('Proses Oiler sukses:', data.message);
                // alert(data.message); // Hapus alert ini
                window.location.href = '{{ route("oiler.list") }}'; // Tambahkan redirect
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
</script>
@endsection