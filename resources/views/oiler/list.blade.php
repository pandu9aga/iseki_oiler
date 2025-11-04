@extends('layouts.app')

@section('content')
<h2 class="text-primary">List Records Oiler</h2>
<hr>

<table id="recordsTable" class="table table-striped table-bordered" style="width:100%">
    <thead>
        <tr>
            <th>No</th> <!-- Header untuk kolom nomor urut -->
            <th>Sequence No</th>
            <th>Scan Time</th>
            <th>Detect Time</th>
        </tr>
    </thead>
    <!-- Tidak perlu kolom untuk 'action' di sini jika tidak ditampilkan -->
</table>

@endsection

@section('scripts')
<script>
    $(document).ready(function() {
        $('#recordsTable').DataTable({
            processing: true,
            serverSide: true,
            pageLength: 50,
            ajax: {
                url: '/iseki_oiler/public/api/records', // Pastikan URL ini benar dan mengembalikan data sesuai format DataTables
                type: 'GET',
                error: function (xhr, error, code) {
                    console.warn("DataTables AJAX Error:", error, code);
                    // Tambahkan penanganan error jika diperlukan
                }
            },
            columns: [
                {
                    // Kolom Nomor Urut
                    data: null, // Tidak mengambil data dari respons JSON
                    name: 'No', // Nama kolom (opsional untuk header)
                    orderable: false, // Kolom ini tidak bisa diurutkan
                    searchable: false, // Kolom ini tidak bisa dicari
                    render: function (data, type, row, meta) {
                        // Gunakan meta.row untuk mendapatkan nomor urut
                        // meta.row adalah indeks baris saat ini (dimulai dari 0)
                        // Tambahkan 1 untuk mendapatkan nomor urut 1, 2, 3, ...
                        return meta.row + 1;
                    }
                },
                {
                    // Kolom Sequence No
                    data: 'Sequence_No_Record',
                    name: 'Sequence_No_Record'
                },
                {
                    // Kolom Scan Time
                    data: 'Scan_Time_Record',
                    name: 'Scan_Time_Record'
                    // Jika kamu ingin memformat waktu di sini, kamu bisa menggunakan render
                    // render: function(data, type, row) {
                    //     if (type === 'display' && data) {
                    //         // Contoh format menggunakan Date (perlu validasi jika data tidak selalu ISO)
                    //         return new Date(data).toLocaleString();
                    //     }
                    //     return data;
                    // }
                },
                {
                    // Kolom Detect Time
                    data: 'Detect_Time_Record',
                    name: 'Detect_Time_Record'
                    // Sama seperti Scan Time, format jika perlu
                },
                // Tambahkan kolom lain jika ada
            ]
        });
    });
</script>
@endsection