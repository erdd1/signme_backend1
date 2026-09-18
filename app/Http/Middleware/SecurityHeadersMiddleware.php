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

        // headers->set() (et non ->header(), spécifique à Illuminate\Http\Response)
        // fonctionne sur TOUS les types de réponse Symfony — y compris
        // StreamedResponse/BinaryFileResponse (ex: téléchargement de sauvegarde),
        // qui n'ont pas cette méthode de commodité Laravel.

        // ─── X-Frame-Options : Empêcher clickjacking ─────────────────────────
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // ─── X-Content-Type-Options : Prévenir MIME type sniffing ────────────
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // ─── X-XSS-Protection : Activer le filtre XSS du navigateur ─────────
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // ─── Referrer-Policy : Contrôler l'envoi du referrer ────────────────
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

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
        $response->headers->set('Content-Security-Policy', $csp);

        // ─── HSTS : Force HTTPS (si activé) ────────────────────────────────
        if (config('security.https.hsts_enabled') && $request->secure()) {
            $maxAge = config('security.https.hsts_max_age', 31536000);
            $response->headers->set('Strict-Transport-Security', "max-age={$maxAge}; includeSubDomains");
        }

        // ─── Permissions-Policy : Contrôler l'accès aux features sensibles ──
        $permissionsPolicy = implode(', ', [
            "geolocation=()",                        // Pas d'accès géolocalisation
            "microphone=()",                         // Pas d'accès microphone
            "camera=()",                             // Pas d'accès caméra
            "payment=()",                            // Pas d'accès Payment API
            "usb=()",                                // Pas d'accès USB
        ]);
        $response->headers->set('Permissions-Policy', $permissionsPolicy);

        // ─── X-Permitted-Cross-Domain-Policies : Flash/PDF ────────────────
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // ─── Server header masking ─────────────────────────────────────────
        // Le header "Server" (nom/version du serveur web) n'est PAS masqué
        // ici — Laravel/PHP ne l'émettent pas eux-mêmes, c'est le serveur web
        // qui le génère. À masquer côté infra avant mise en production :
        // Nginx (`server_tokens off;`) et PHP-FPM (`expose_php = Off`).

        return $response;
    }
}
