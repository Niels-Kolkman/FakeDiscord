<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening headers on every web response:
 *
 * - no framing by other sites (clickjacking a "Delete server" or "Ban"
 *   button), through both X-Frame-Options and CSP frame-ancestors;
 * - no MIME sniffing, so a response is only ever read as its own type;
 * - only the origin is sent as referrer to other sites, never the path
 *   (server and channel ids, signed attachment links).
 *
 * A header the response already has wins: AttachmentController sends its
 * own stricter sandbox CSP.
 */
class SecurityHeaders
{
    /** @var array<string, string> */
    public const HEADERS = [
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => "frame-ancestors 'none'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
