<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API PRESENSI YBWSA</title>
    <link href="https://fonts.bunny.net/css?family=poppins:400,600,700&display=swap" rel="stylesheet">

    <style>
        body {
            margin: 0;
            height: 100vh;
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #23a04dff, #1b833aff);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .container {
            max-width: 700px;
            padding: 20px;
        }

        h1 {
            font-size: 3rem;
            font-weight: 700;
            letter-spacing: 1px;
            margin-bottom: 0.5rem;
            text-shadow: 2px 4px 8px rgba(0, 0, 0, 0.3);
        }

        p {
            font-size: 1.2rem;
            font-weight: 400;
            opacity: 0.9;
        }

        .glow {
            animation: glow 3s infinite alternate;
        }

        @keyframes glow {
            from { text-shadow: 0 0 10px #fff, 0 0 20px #93c5fd, 0 0 30px #3b82f6; }
            to { text-shadow: 0 0 20px #fff, 0 0 40px #2563eb, 0 0 60px #1d4ed8; }
        }

        .footer {
            position: absolute;
            bottom: 15px;
            width: 100%;
            text-align: center;
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.6);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="glow">API PRESENSI YBWSA</h1>
    </div>

    <div class="footer">
        &copy; {{ date('Y') }} YBWSA. All rights reserved.
    </div>
</body>
</html>
