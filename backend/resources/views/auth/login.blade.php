<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Signage Admin — Sign in</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #0f172a;
        }
        form {
            width: 320px;
            padding: 2rem;
            border-radius: 12px;
            background: #1e293b;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        h1 { font-size: 1.1rem; color: #f8fafc; margin: 0 0 1.5rem; }
        label { display: block; font-size: 0.85rem; color: #cbd5e1; margin-bottom: 0.3rem; }
        input {
            width: 100%;
            padding: 0.55rem 0.7rem;
            margin-bottom: 1rem;
            border-radius: 6px;
            border: 1px solid #334155;
            background: #0f172a;
            color: #f8fafc;
        }
        button {
            width: 100%;
            padding: 0.6rem;
            border: none;
            border-radius: 6px;
            background: #6366f1;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
        }
        .error { color: #fca5a5; font-size: 0.8rem; margin: -0.75rem 0 1rem; }
    </style>
</head>
<body>
    <form method="POST" action="{{ url('/admin/login') }}">
        @csrf
        <h1>Signage Admin</h1>

        @error('email')
            <p class="error">{{ $message }}</p>
        @enderror

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autofocus required>

        <label for="password">Password</label>
        <input id="password" name="password" type="password" required>

        <button type="submit">Sign in</button>
    </form>
</body>
</html>
