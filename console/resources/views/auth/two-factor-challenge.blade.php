<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2FA Verification - TGStock License Console</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        body { background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 8px; width: 100%; max-width: 420px; padding: 32px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h1 { font-size: 1.35rem; font-weight: 700; margin-bottom: 8px; text-align: center; }
        p.subtitle { color: #94a3b8; font-size: 0.85rem; text-align: center; margin-bottom: 24px; line-height: 1.4; }
        .form-group { margin-bottom: 18px; }
        label { display: block; font-size: 0.82rem; font-weight: 600; color: #94a3b8; margin-bottom: 6px; }
        input[type="text"] {
            width: 100%; padding: 12px 14px; background: #0f172a; border: 1px solid #334155; border-radius: 6px; color: #f8fafc; font-size: 1.2rem; text-align: center; letter-spacing: 4px; font-weight: 700;
        }
        input:focus { outline: none; border-color: #3b82f6; }
        .btn { width: 100%; padding: 11px; background: #3b82f6; color: #fff; border: none; border-radius: 6px; font-weight: 600; font-size: 0.92rem; cursor: pointer; transition: background 0.15s; margin-top: 8px; }
        .btn:hover { background: #2563eb; }
        .alert { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); color: #fca5a5; padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 18px; }
        .toggle-link { display: block; text-align: center; color: #60a5fa; font-size: 0.82rem; margin-top: 18px; text-decoration: none; cursor: pointer; }
        .toggle-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Two-Factor Verification</h1>
        <p class="subtitle">Please enter the 6-digit TOTP code from your Authenticator app to access the console.</p>

        @if ($errors->any())
            <div class="alert">
                <ul style="padding-left: 18px;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('2fa.verify') }}" method="POST">
            @csrf
            <div id="totp-group" class="form-group">
                <label for="code">Authentication Code (6 digits)</label>
                <input type="text" id="code" name="code" maxlength="6" autofocus placeholder="123456" inputmode="numeric">
            </div>

            <div id="backup-group" class="form-group" style="display: none;">
                <label for="backup_code">Emergency Backup Code (XXXX-XXXX)</label>
                <input type="text" id="backup_code" name="backup_code" placeholder="ABCD-1234">
            </div>

            <button type="submit" class="btn">Verify & Proceed</button>
        </form>

        <a class="toggle-link" id="toggle-btn" onclick="toggleMode()">Use an emergency backup code instead</a>
    </div>

    <script>
        var isBackup = false;
        function toggleMode() {
            isBackup = !isBackup;
            var totpGroup = document.getElementById('totp-group');
            var backupGroup = document.getElementById('backup-group');
            var toggleBtn = document.getElementById('toggle-btn');
            var codeInput = document.getElementById('code');
            var backupInput = document.getElementById('backup_code');

            if (isBackup) {
                totpGroup.style.display = 'none';
                backupGroup.style.display = 'block';
                toggleBtn.innerText = 'Use 6-digit authenticator code instead';
                backupInput.focus();
            } else {
                totpGroup.style.display = 'block';
                backupGroup.style.display = 'none';
                toggleBtn.innerText = 'Use an emergency backup code instead';
                codeInput.focus();
            }
        }
    </script>
</body>
</html>
