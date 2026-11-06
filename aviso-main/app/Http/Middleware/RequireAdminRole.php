<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check() || Auth::user()->role !== 'admin') {
            Auth::logout();
            return redirect()->route('login');
        }

        // Deactivated while logged in: end the session and say why on the login page.
        if (Auth::user()->isDeactivated()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('deactivated', User::DEACTIVATED_SESSION_MESSAGE);
        }

        return $next($request);
    }
}
