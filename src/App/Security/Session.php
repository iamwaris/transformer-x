<?php

declare(strict_types=1);

namespace TransformerX\App\Security;

use TransformerX\App\Settings;

/**
 * Hardened PHP session: strict mode, HttpOnly + SameSite=Strict cookie scoped to the app path,
 * idle and absolute timeouts, ID rotation on sign-in.
 */
final class Session
{
    private const ABSOLUTE_LIFETIME = 8 * 3600;

    public static function start(string $basePath, bool $https): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.gc_maxlifetime', (string) self::ABSOLUTE_LIFETIME);
        session_cache_limiter('');   // keep our own Cache-Control: no-store
        session_name('TXSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => ($basePath === '' ? '' : $basePath) . '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();

        $now = time();
        $idle = Settings::int('session_idle_minutes') * 60;
        if (isset($_SESSION['uid'])) {
            $expired = ($now - (int) ($_SESSION['last_seen'] ?? 0)) > $idle
                || ($now - (int) ($_SESSION['started'] ?? 0)) > self::ABSOLUTE_LIFETIME;
            if ($expired) {
                self::destroy();
                session_start();
                $_SESSION['expired'] = true;
            }
        }
        $_SESSION['last_seen'] = $now;
    }

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION = ['uid' => $userId, 'started' => time(), 'last_seen' => time(), 'csrf' => bin2hex(random_bytes(32))];
    }

    public static function userId(): ?int
    {
        return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    }

    /** True once, right after an idle/absolute timeout ended the previous session. */
    public static function takeExpiredFlag(): bool
    {
        $flag = !empty($_SESSION['expired']);
        unset($_SESSION['expired']);
        return $flag;
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
            session_destroy();
        }
    }
}
