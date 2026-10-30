<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f4f4f5; --card: #fff; --text: #18181b; --muted: #52525b; --accent: #d97706; --error: #b91c1c; --border: #d4d4d8; }
        @media (prefers-color-scheme: dark) { :root { --bg: #09090b; --card: #18181b; --text: #f4f4f5; --muted: #a1a1aa; --border: #3f3f46; --error: #f87171; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, sans-serif; }
        main { width: 100%; max-width: 400px; background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 32px 24px; }
        h1 { font-size: 1.375rem; margin: 0 0 8px; }
        p { color: var(--muted); margin: 0 0 20px; }
        label { display: block; font-weight: 600; margin: 0 0 4px; }
        input { width: 100%; padding: 10px 12px; margin: 0 0 16px; border: 1px solid var(--border); border-radius: 8px; background: transparent; color: var(--text); font: inherit; }
        button, .button { display: inline-block; width: 100%; padding: 10px 12px; border: 0; border-radius: 8px; background: var(--accent); color: #fff; font: inherit; font-weight: 600; text-align: center; text-decoration: none; cursor: pointer; }
        .error { color: var(--error); margin: -12px 0 16px; font-size: .875rem; }
        .status { color: var(--text); }
        a { color: var(--accent); }
    </style>
</head>
<body>
    <main>
        @yield('content')
    </main>
</body>
</html>
