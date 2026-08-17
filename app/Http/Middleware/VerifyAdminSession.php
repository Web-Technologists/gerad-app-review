<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyAdminSession
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!session()->get('admin_authenticated')) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Unauthorized. Admin session required.'], 401);
            }
            return redirect()->route('admin.login');
        }

        return $next($request);
    }
}
