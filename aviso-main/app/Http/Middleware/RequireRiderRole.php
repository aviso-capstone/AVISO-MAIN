<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireRiderRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('sanctum')->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->role !== 'rider') {
            return response()->json(['message' => 'Forbidden. Rider access only.'], 403);
        }

        // Bind the authenticated user so auth() helper works in controllers
        Auth::setUser($user);

        return $next($request);
    }
}
