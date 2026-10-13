<?php

declare(strict_types=1);

namespace TransformerX\App\Security;

final class SecurityHeaders
{
    public static function send(bool $https, bool $isApi): void
    {
        header_remove('X-Powered-By');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        if ($https) {
            header('Strict-Transport-Security: max-age=31536000');
        }
        header('Cache-Control: no-store, max-age=0');
        if ($isApi) {
            header('X-Robots-Tag: noindex');
        }
    }
}
