<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
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

        // Deactivated by an admin: end this app login and tell the rider why.
        if ($user->isDeactivated()) {
            $token = $user->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            return response()->json([
                'message' => User::DEACTIVATED_SESSION_MESSAGE,
                'code'    => 'account_deactivated',
            ], 403);
        }

        // Bind the authenticated user so auth() helper works in controllers
        Auth::setUser($user);

        return $next($request);
    }
}
