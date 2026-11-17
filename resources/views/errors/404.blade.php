<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Halaman Tidak Ditemukan</title>
    @vite(['resources/css/app.css'])
    <style>
        body {
            margin: 0;
            padding: 0;
        }
        .error-container {
            min-h: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .error-content {
            text-align: center;
            max-width: 600px;
        }
        .error-code {
            font-size: 8rem;
            font-weight: 900;
            color: var(--color-accent);
            margin: 0;
            line-height: 1;
            font-family: var(--font-display);
        }
        .error-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 1rem 0;
            color: var(--color-fg);
        }
        .error-message {
            font-size: 1rem;
            color: var(--color-muted);
            margin: 1.5rem 0;
            line-height: 1.6;
        }
        .error-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 2rem;
        }
        .error-button {
            padding: 0.75rem 2rem;
            border-radius: var(--radius-md);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            font-size: 1rem;
        }
        .error-button-primary {
            background: var(--color-accent);
            color: var(--color-accent-ink);
        }
        .error-button-primary:hover {
            filter: brightness(1.1);
            transform: translateY(-2px);
        }
        .error-button-secondary {
            background: var(--color-surface);
            color: var(--color-fg);
            border: 1px solid var(--color-line);
        }
        .error-button-secondary:hover {
            background: var(--color-surface-2);
        }
    </style>
</head>
<body class="bg-bg text-fg">
    <div class="error-container">
        <div class="error-content">
            <h1 class="error-code">404</h1>
            <h2 class="error-title">Halaman Tidak Ditemukan</h2>
            <p class="error-message">Workspace yang Anda cari tidak ada atau belum tersedia. Periksa URL Anda atau kembali ke halaman utama.</p>
            <div class="error-actions">
                <a href="http://app.localhost:8000" class="error-button error-button-primary">Kembali ke Dashboard</a>
                <a href="http://app.localhost:8000/workspaces" class="error-button error-button-secondary">Lihat Workspace</a>
            </div>
        </div>
    </div>
</body>
</html>
