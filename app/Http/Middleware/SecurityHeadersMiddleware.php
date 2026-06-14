<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SecurityHeadersMiddleware
 *
 * Ajoute les en-têtes HTTP de sécurité à toutes les réponses
 * PHASE 2
 */
class SecurityHeadersMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // ─── X-Frame-Options : Empêcher clickjacking ─────────────────────────
        $response->header('X-Frame-Options', 'SAMEORIGIN');

        // ─── X-Content-Type-Options : Prévenir MIME type sniffing ────────────
        $response->header('X-Content-Type-Options', 'nosniff');

        // ─── X-XSS-Protection : Activer le filtre XSS du navigateur ─────────
        $response->header('X-XSS-Protection', '1; mode=block');

        // ─── Referrer-Policy : Contrôler l'envoi du referrer ────────────────
        $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');

        // ─── Content-Security-Policy : Contrôle strict du contenu ───────────
        $csp = implode('; ', [
            "default-src 'self'",                    // Par défaut, accept uniquement same-origin
            "script-src 'self'",                     // Scripts depuis notre domaine uniquement
            "style-src 'self' 'unsafe-inline'",      // CSS (unsafe-inline parfois nécessaire)
            "img-src 'self' data: https:",           // Images de notre domaine, data URIs, https
            "font-src 'self'",                       // Fonts depuis notre domaine
            "connect-src 'self'",                    // AJAX/fetch depuis notre domaine
            "frame-ancestors 'none'",                // Pas d'embedding en frame
            "base-uri 'self'",                       // Restrict <base> tag
            "form-action 'self'",                    // Restrict form submissions
        ]);
        $response->header('Content-Security-Policy', $csp);

        // ─── HSTS : Force HTTPS (si activé) ────────────────────────────────
        if (config('security.https.hsts_enabled') && $request->secure()) {
            $maxAge = config('security.https.hsts_max_age', 31536000);
            $response->header('Strict-Transport-Security', "max-age={$maxAge}; includeSubDomains");
        }

        // ─── Permissions-Policy : Contrôler l'accès aux features sensibles ──
        $permissionsPolicy = implode(', ', [
            "geolocation=()",                        // Pas d'accès géolocalisation
            "microphone=()",                         // Pas d'accès microphone
            "camera=()",                             // Pas d'accès caméra
            "payment=()",                            // Pas d'accès Payment API
            "usb=()",                                // Pas d'accès USB
        ]);
        $response->header('Permissions-Policy', $permissionsPolicy);

        // ─── X-Permitted-Cross-Domain-Policies : Flash/PDF ────────────────
        $response->header('X-Permitted-Cross-Domain-Policies', 'none');

        // ─── Server header masking ─────────────────────────────────────────
        // Déjà géré dans config/app.php: 'hide_app_info' => true

        return $response;
    }
}
