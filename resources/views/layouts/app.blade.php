<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Oli Detection</title>
    <link href="{{ asset('assets/css/bootstrap.min.css') }}" rel="stylesheet">
    <!-- Tambahkan CSS DataTables jika digunakan -->
    <link rel="stylesheet" href="{{ asset('assets/css/dataTables.bootstrap5.min.css') }}">
    <style>
        .text-primary {
            color: #d63384 !important;
        }

        .bg-pink {
            background-color: #d63384 !important;
        }
    </style>
    @yield('style')
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-pink">
        <div class="container mx-4">
            <a class="navbar-brand" href="{{ route('home') }}">Oli Detection</a>
            <div class="navbar-nav">
                <a class="nav-link" href="{{ route('oiler.scan') }}">Scan</a>
                <a class="nav-link" href="{{ route('oiler.list') }}">List</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @yield('content')
    </div>

    <script src="{{ asset('assets/js/bootstrap.bundle.min.js') }}"></script>
    <!-- Tambahkan JS DataTables jika digunakan -->
    <script src="{{ asset('assets/js/jquery-3.7.1.min.js') }}"></script>
    <script src="{{ asset('assets/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ asset('assets/js/dataTables.bootstrap5.min.js') }}"></script>
    @yield('scripts')
</body>
</html>