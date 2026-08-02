<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Deliberately plain session auth — no Breeze/Fortify/Jetstream scaffold.
 * The admin panel is a same-origin SPA served by this same Laravel app
 * (see routes/web.php), so a session cookie + the standard CSRF token is
 * the entire auth mechanism the admin Vue app needs; anything more here
 * would be unused surface area for a demo with one seeded user.
 */
class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, remember: true)) {
            return back()->withErrors(['email' => 'Invalid credentials.'])->onlyInput('email');
        }

        // Regenerating the session id on privilege escalation prevents
        // session fixation — a pre-login session id must never carry
        // over into an authenticated one.
        $request->session()->regenerate();

        return redirect()->intended('/admin');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/admin/login');
    }
}
