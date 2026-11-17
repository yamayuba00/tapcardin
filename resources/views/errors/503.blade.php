<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>503 - Layanan Sedang Tidak Tersedia</title>
    @vite(['resources/css/app.css'])
    <style>
        body {
            margin: 0;
            padding: 0;
        }
        .error-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .error-content {
            text-align: center;
            max-width: 600px;
            width: 100%;
        }
        .error-logo {
            width: 64px;
            height: 64px;
            margin: 0 auto 1.5rem;
        }
        .error-code {
            font-size: 8rem;
            font-weight: 900;
            color: var(--color-warn);
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
            background: var(--color-warn);
            color: var(--color-on-solid);
        }
        .error-button-primary:hover {
            filter: brightness(1.1);
            transform: translateY(-2px);
        }
    </style>
</head>
<body class="bg-bg text-fg">
    <div class="error-container">
        <div class="error-content">
            <img src="/favicon.svg" alt="TapCard" class="error-logo">
            <h1 class="error-code">503</h1>
            <h2 class="error-title">Layanan Sedang Tidak Tersedia</h2>
            <p class="error-message">Kami sedang melakukan pemeliharaan. Silakan coba lagi dalam beberapa saat.</p>
            <div class="error-actions">
                <button class="error-button error-button-primary" onclick="location.reload()">Muat Ulang</button>
            </div>
        </div>
    </div>
</body>
</html>
