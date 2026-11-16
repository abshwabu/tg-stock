<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup 2FA - TGStock License Console</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        body { background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 8px; width: 100%; max-width: 500px; padding: 32px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h1 { font-size: 1.35rem; font-weight: 700; margin-bottom: 8px; text-align: center; }
        p.subtitle { color: #94a3b8; font-size: 0.85rem; text-align: center; margin-bottom: 24px; line-height: 1.4; }
        .box { background: #0f172a; border: 1px solid #334155; border-radius: 6px; padding: 14px; margin-bottom: 20px; font-size: 0.88rem; }
        .code-box { font-family: monospace; font-size: 1.1rem; text-align: center; letter-spacing: 2px; color: #60a5fa; font-weight: 700; padding: 10px; background: #0b1120; border-radius: 4px; margin: 8px 0; }
        .backup-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
        .backup-item { font-family: monospace; background: #0b1120; padding: 6px; text-align: center; border-radius: 4px; font-size: 0.85rem; color: #34d399; }
        .form-group { margin-bottom: 18px; }
        label { display: block; font-size: 0.82rem; font-weight: 600; color: #94a3b8; margin-bottom: 6px; }
        input[type="text"] {
            width: 100%; padding: 12px 14px; background: #0f172a; border: 1px solid #334155; border-radius: 6px; color: #f8fafc; font-size: 1.1rem; text-align: center; letter-spacing: 3px; font-weight: 700;
        }
        input:focus { outline: none; border-color: #3b82f6; }
        .btn { width: 100%; padding: 11px; background: #10b981; color: #fff; border: none; border-radius: 6px; font-weight: 600; font-size: 0.92rem; cursor: pointer; transition: background 0.15s; }
        .btn:hover { background: #059669; }
        .alert { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); color: #fca5a5; padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; margin-bottom: 18px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Setup Two-Factor Authentication</h1>
        <p class="subtitle">Mandatory security requirement for all License Console admin accounts.</p>

        @if ($errors->any())
            <div class="alert">
                <ul style="padding-left: 18px;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="box">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <strong>1. Scan QR code or enter secret in Authenticator app:</strong>
                <button type="button" class="btn" style="width: auto; padding: 4px 10px; font-size: 0.75rem; background: #3b82f6;" onclick="copyToClipboard('{{ $secret }}', this)">
                    📋 Copy Secret
                </button>
            </div>
            <div class="code-box" id="secret-code">{{ $secret }}</div>
            <p style="font-size: 0.78rem; color: #94a3b8; word-break: break-all;">URI: {{ $qrUri }}</p>
        </div>

        <div class="box">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <strong>2. Save emergency backup codes:</strong>
                <button type="button" class="btn" style="width: auto; padding: 4px 10px; font-size: 0.75rem; background: #3b82f6;" onclick="copyToClipboard('{{ implode("\n", $backupCodes) }}', this)">
                    📋 Copy All Codes
                </button>
            </div>
            <p style="font-size: 0.78rem; color: #94a3b8; margin-top: 4px;">Store these in a safe password manager. Each code can be used once if you lose device access.</p>
            <div class="backup-grid">
                @foreach ($backupCodes as $bCode)
                    <div class="backup-item">{{ $bCode }}</div>
                @endforeach
            </div>
        </div>

        <form action="{{ route('2fa.confirm') }}" method="POST">
            @csrf
            <div class="form-group">
                <label for="code">3. Enter verification code from your app:</label>
                <input type="text" id="code" name="code" maxlength="6" autofocus placeholder="123456" inputmode="numeric" required>
            </div>

            <button type="submit" class="btn">Confirm & Enable 2FA</button>
        </form>
    </div>

    <script>
        function flashCopied(btn) {
            if (!btn) return;
            var orig = btn.innerHTML;
            btn.innerHTML = '✅ Copied!';
            setTimeout(function() { btn.innerHTML = orig; }, 2000);
        }

        function fallbackCopy(text, btn) {
            var ta = document.createElement("textarea");
            ta.value = text;
            ta.style.position = "fixed";
            ta.style.left = "-9999px";
            ta.setAttribute("readonly", "");
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            try {
                if (document.execCommand('copy')) {
                    flashCopied(btn);
                } else {
                    prompt("Copy manually:", text);
                }
            } catch (e) {
                prompt("Copy manually:", text);
            }
            document.body.removeChild(ta);
        }

        function copyToClipboard(text, btn) {
            if (!text) return;
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function() {
                    flashCopied(btn);
                }).catch(function() {
                    fallbackCopy(text, btn);
                });
            } else {
                fallbackCopy(text, btn);
            }
        }
    </script>
</body>
</html>
