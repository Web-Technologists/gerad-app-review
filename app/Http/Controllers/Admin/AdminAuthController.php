<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdminAuthController extends Controller
{
    /**
     * Display the login form.
     */
    public function showLoginForm()
    {
        if (session()->get('admin_authenticated')) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    /**
     * Handle admin login request.
     */
    public function login(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $configuredPassword = config('app.admin_password', 'admin123');

        if ($request->input('password') === $configuredPassword) {
            session(['admin_authenticated' => true]);
            
            return redirect()->route('admin.dashboard')
                ->with('success', 'Logged in to Central Admin Panel successfully!');
        }

        return back()->withErrors([
            'password' => 'The provided password does not match our records.',
        ])->withInput();
    }

    /**
     * Handle admin logout request.
     */
    public function logout(Request $request)
    {
        session()->forget('admin_authenticated');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')
            ->with('success', 'Logged out successfully.');
    }
}
