<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Console') - TGStock License Console</title>
    <style>
        :root {
            --bg-main: #0f172a;
            --bg-card: #1e293b;
            --bg-hover: #334155;
            --border-color: #334155;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #06b6d4;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: var(--bg-main); color: var(--text-main); display: flex; min-height: 100vh; }
        
        /* Sidebar */
        aside { width: 260px; background: var(--bg-card); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; flex-shrink: 0; }
        .brand { padding: 24px 20px; font-size: 1.15rem; font-weight: 700; letter-spacing: -0.5px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 10px; color: var(--text-main); text-decoration: none; }
        .brand-badge { background: var(--primary); color: #fff; font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; text-transform: uppercase; font-weight: 800; }
        nav { flex: 1; padding: 16px 12px; display: flex; flex-direction: column; gap: 4px; overflow-y: auto; }
        nav a { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: 6px; color: var(--text-muted); text-decoration: none; font-size: 0.9rem; font-weight: 500; transition: all 0.15s; }
        nav a:hover, nav a.active { background: var(--bg-hover); color: var(--text-main); }
        nav a.active { background: #1e3a8a; color: #93c5fd; font-weight: 600; }
        .nav-section { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.8px; color: #64748b; margin: 16px 14px 6px; font-weight: 700; }
        
        /* Topbar & Content */
        .main-wrapper { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        header { height: 64px; border-bottom: 1px solid var(--border-color); background: var(--bg-card); display: flex; align-items: center; justify-content: space-between; padding: 0 28px; }
        .user-status { display: flex; align-items: center; gap: 14px; font-size: 0.88rem; }
        .role-badge { padding: 3px 8px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .role-super { background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.4); }
        .role-support { background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.4); }
        
        main { flex: 1; padding: 28px; overflow-y: auto; }
        
        /* Cards & Grids */
        .card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 20px; margin-bottom: 24px; }
        .grid-4 { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 18px; margin-bottom: 24px; }
        .grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; }
        .stat-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; padding: 18px; }
        .stat-title { font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); margin-bottom: 6px; }
        .stat-value { font-size: 1.8rem; font-weight: 700; color: var(--text-main); }
        
        /* Tables */
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem; }
        th { padding: 12px 14px; border-bottom: 1px solid var(--border-color); color: var(--text-muted); font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; }
        td { padding: 12px 14px; border-bottom: 1px solid rgba(51, 65, 85, 0.5); vertical-align: middle; }
        tr:hover td { background: rgba(255, 255, 255, 0.02); }
        
        /* Badges */
        .badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
        .badge-active { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .badge-expiring { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        .badge-grace { background: rgba(249, 115, 22, 0.2); color: #fb923c; }
        .badge-expired { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        .badge-revoked { background: rgba(185, 28, 28, 0.4); color: #fca5a5; }
        .badge-suspended { background: rgba(100, 116, 139, 0.4); color: #cbd5e1; }
        
        /* Buttons & Inputs */
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 6px; font-size: 0.85rem; font-weight: 600; cursor: pointer; text-decoration: none; border: 1px solid transparent; transition: all 0.15s; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-secondary { background: var(--bg-hover); color: var(--text-main); border-color: var(--border-color); }
        .btn-secondary:hover { background: #475569; }
        .btn-danger { background: rgba(239, 68, 68, 0.2); color: #f87171; border-color: rgba(239, 68, 68, 0.4); }
        .btn-danger:hover { background: rgba(239, 68, 68, 0.4); }
        .btn-success { background: rgba(16, 185, 129, 0.2); color: #34d399; border-color: rgba(16, 185, 129, 0.4); }
        .btn-success:hover { background: rgba(16, 185, 129, 0.4); }
        .btn-sm { padding: 4px 8px; font-size: 0.78rem; border-radius: 4px; }
        
        .form-group { margin-bottom: 16px; }
        label { display: block; font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 6px; }
        input[type="text"], input[type="email"], input[type="password"], input[type="number"], input[type="date"], select, textarea {
            width: 100%; padding: 9px 12px; background: #0f172a; border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-main); font-size: 0.9rem;
        }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--primary); }
        
        /* Alerts */
        .alert { padding: 12px 16px; border-radius: 6px; font-size: 0.88rem; margin-bottom: 20px; }
        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); color: #6ee7b7; }
        .alert-error { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); color: #fca5a5; }
        .alert-warning { background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.4); color: #fcd34d; }
        
        /* Utilities */
        .flex-between { display: flex; justify-content: space-between; align-items: center; }
        .code-snippet { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; background: #0b1120; border: 1px solid #1e293b; padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; word-break: break-all; }
    </style>
</head>
<body>
    <aside>
        <a href="{{ route('admin.dashboard') }}" class="brand">
            TGStock <span class="brand-badge">Console</span>
        </a>
        <nav>
            <div class="nav-section">Management</div>
            <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">Dashboard</a>
            <a href="{{ route('admin.licenses.wizard') }}" class="{{ request()->routeIs('admin.licenses.wizard') ? 'active' : '' }}">✨ Issue Token</a>
            <a href="{{ route('admin.licenses.index') }}" class="{{ request()->routeIs('admin.licenses.index*') && !request()->routeIs('admin.licenses.wizard') ? 'active' : '' }}">Licenses</a>
            <a href="{{ route('admin.customers.index') }}" class="{{ request()->routeIs('admin.customers.*') ? 'active' : '' }}">Customers</a>
            <a href="{{ route('admin.payments.index') }}" class="{{ request()->routeIs('admin.payments.*') ? 'active' : '' }}">Payments</a>
            <a href="{{ route('admin.plans.index') }}" class="{{ request()->routeIs('admin.plans.*') ? 'active' : '' }}">Plans</a>
            
            <div class="nav-section">Security & Operations</div>
            <a href="{{ route('admin.keys.index') }}" class="{{ request()->routeIs('admin.keys.*') ? 'active' : '' }}">Root Keys</a>
            @if(auth()->user()?->isSuperAdmin())
                <a href="{{ route('admin.settings.index') }}" class="{{ request()->routeIs('admin.settings.*') ? 'active' : '' }}">Settings</a>
            @endif
            <a href="{{ route('admin.audit_logs.index') }}" class="{{ request()->routeIs('admin.audit_logs.*') ? 'active' : '' }}">Audit Logs</a>
            <a href="{{ route('admin.health') }}" class="{{ request()->routeIs('admin.health') ? 'active' : '' }}">Health & Backup</a>
        </nav>
    </aside>

    <div class="main-wrapper">
        <header>
            <div style="font-weight: 600; font-size: 0.95rem;">@yield('header_title', 'TGStock Super Admin')</div>
            <div class="user-status">
                <span class="role-badge {{ auth()->user()?->isSuperAdmin() ? 'role-super' : 'role-support' }}">
                    {{ auth()->user()?->role }}
                </span>
                <span>{{ auth()->user()?->name }}</span>
                <form action="{{ route('logout') }}" method="POST" style="display:inline;">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">Logout</button>
                </form>
            </div>
        </header>

        <main>
            @if (session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="alert alert-error">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="alert alert-error">
                    <ul style="padding-left: 20px;">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </main>
    </div>

    <script>
        function flashCopied(buttonElement) {
            if (!buttonElement) return;
            var original = buttonElement.innerHTML;
            buttonElement.innerHTML = '✅ Copied!';
            buttonElement.classList.add('btn-success');
            setTimeout(function() {
                buttonElement.innerHTML = original;
                buttonElement.classList.remove('btn-success');
            }, 2000);
        }

        function fallbackCopyText(text, buttonElement) {
            var textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.position = "fixed";
            textArea.style.left = "-9999px";
            textArea.style.top = "-9999px";
            textArea.setAttribute("readonly", "");
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            try {
                var successful = document.execCommand('copy');
                if (successful) {
                    flashCopied(buttonElement);
                } else {
                    prompt("Copy manually (Ctrl+C / Cmd+C):", text);
                }
            } catch (err) {
                prompt("Copy manually (Ctrl+C / Cmd+C):", text);
            }
            document.body.removeChild(textArea);
        }

        function copyToClipboard(text, buttonElement) {
            if (!text) return;
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function() {
                    flashCopied(buttonElement);
                }).catch(function() {
                    fallbackCopyText(text, buttonElement);
                });
            } else {
                fallbackCopyText(text, buttonElement);
            }
        }

        document.addEventListener('click', function(e) {
            var target = e.target.closest('[data-copy-target], [data-copy-text]');
            if (!target) return;
            e.preventDefault();
            var text = '';
            if (target.dataset.copyText) {
                text = target.dataset.copyText;
            } else if (target.dataset.copyTarget) {
                var el = document.querySelector(target.dataset.copyTarget);
                if (el) {
                    text = el.value || el.innerText || '';
                }
            }
            if (text) {
                copyToClipboard(text.trim(), target);
            }
        });
    </script>
</body>
</html>
