<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser safety headers for every web (admin) response.
 *
 * Main purpose is anti-clickjacking: no other site may load the admin
 * console inside an invisible frame and trick an admin into clicking
 * "Delete user" or "Resolve SOS".
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // geolocation stays allowed for our own pages: the map's "locate me" button uses it.
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
