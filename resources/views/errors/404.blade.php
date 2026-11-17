<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Halaman Tidak Ditemukan</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 min-h-screen flex items-center justify-center">
    <div class="text-center px-6">
        <div class="mb-8">
            <div class="text-9xl font-black text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-blue-500">
                404
            </div>
        </div>

        <h1 class="text-4xl md:text-5xl font-bold text-white mb-4">
            Halaman Tidak Ditemukan
        </h1>

        <p class="text-xl text-slate-300 mb-8 max-w-lg mx-auto">
            Workspace yang Anda cari tidak ada atau belum tersedia. Periksa URL Anda atau kembali ke halaman utama.
        </p>

        <div class="flex gap-4 justify-center flex-wrap">
            <a href="http://app.localhost:8000" class="px-8 py-3 bg-cyan-500 hover:bg-cyan-600 text-white font-semibold rounded-lg transition transform hover:scale-105">
                Kembali ke Dashboard
            </a>
            <a href="http://app.localhost:8000/workspaces" class="px-8 py-3 bg-slate-700 hover:bg-slate-600 text-white font-semibold rounded-lg transition">
                Lihat Workspace
            </a>
        </div>
    </div>
</body>
</html>
