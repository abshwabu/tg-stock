<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - TGStock License Console</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        body { background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
        .login-card { background: #1e293b; border: 1px solid #334155; border-radius: 8px; width: 100%; max-width: 400px; padding: 32px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h1 { font-size: 1.35rem; font-weight: 700; margin-bottom: 8px; text-align: center; }
        p.subtitle { color: #94a3b8; font-size: 0.85rem; text-align: center; margin-bottom: 24px; }
        .form-group { margin-bottom: 18px; }
        label { display: block; font-size: 0.82rem; font-weight: 600; color: #94a3b8; margin-bottom: 6px; }
        input[type="email"], input[type="password"] {
            width: 100%; padding: 10px 14px; background: #0f172a; border: 1px solid #334155; border-radius: 6px; color: #f8fafc; font-size: 0.92rem;
        }
        input:focus { outline: none; border-color: #3b82f6; }
        .btn { width: 100%; padding: 11px; background: #3b82f6; color: #fff; border: none; border-radius: 6px; font-weight: 600; font-size: 0.92rem; cursor: pointer; transition: background 0.15s; }
        .btn:hover { background: #2563eb; }
        .alert { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); color: #fca5a5; padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 18px; }
    </style>
</head>
<body>
    <div class="login-card">
        <h1>TGStock Console</h1>
        <p class="subtitle">Super Admin & License Management Portal</p>

        @if ($errors->any())
            <div class="alert">
                <ul style="padding-left: 18px;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('login.post') }}" method="POST">
            @csrf
            <div class="form-group">
                <label for="email">Admin Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn">Sign In</button>
        </form>
    </div>
</body>
</html>
