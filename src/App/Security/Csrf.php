<?php

declare(strict_types=1);

namespace TransformerX\App\Security;

use TransformerX\App\Http\HttpError;
use TransformerX\App\Http\Request;

/** Synchroniser token: every state-changing request must echo the session token in X-CSRF-Token. */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function verify(Request $request): void
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $sent = $request->header('x-csrf-token') ?? '';
        $expected = $_SESSION['csrf'] ?? '';
        if (!is_string($expected) || $expected === '' || !hash_equals($expected, $sent)) {
            // 403 rather than Laravel-style 419: Apache turns unknown status codes into 500.
            throw new HttpError(403, 'csrf', 'Your session has changed. Reload the page and try again.');
        }
        // Defence in depth: browsers send Origin on cross-site writes; it must be this site.
        $origin = $request->header('origin');
        if ($origin !== null && $origin !== 'null') {
            $host = $request->header('host') ?? '';
            if (parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '') !== $host) {
                throw HttpError::forbidden('Cross-site request blocked.');
            }
        }
    }
}
