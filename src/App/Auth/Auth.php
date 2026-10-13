<?php

declare(strict_types=1);

namespace TransformerX\App\Auth;

use TransformerX\App\AuditLog;
use TransformerX\App\Db;
use TransformerX\App\Http\HttpError;
use TransformerX\App\Http\Request;
use TransformerX\App\Security\Session;
use TransformerX\App\Settings;

/** Sign-in, lockout, IP rate limiting, and the signed-in user for the current request. */
final class Auth
{
    private const IP_WINDOW_MINUTES = 15;
    private const IP_MAX_FAILURES = 20;

    private static ?array $user = null;
    private static bool $loaded = false;

    /** The signed-in, active user, re-read from the database on every request. */
    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = Session::userId();
            if ($id !== null) {
                $u = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
                if ($u === null || !$u['is_active']) {
                    Session::destroy();
                } else {
                    self::$user = $u;
                }
            }
        }
        return self::$user;
    }

    /** Requires a signed-in user who has finished any forced password change. */
    public static function require(?string $role = null): array
    {
        $u = self::user();
        if ($u === null) {
            throw HttpError::unauthorized();
        }
        if ($u['must_change_password']) {
            throw new HttpError(403, 'password_change_required', 'Choose a new password before continuing.');
        }
        if ($role === 'admin' && $u['role'] !== 'admin') {
            throw HttpError::forbidden();
        }
        return $u;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? null) === 'admin';
    }

    public static function login(Request $request, string $username, string $password): array
    {
        $since = date('Y-m-d H:i:s', time() - self::IP_WINDOW_MINUTES * 60);
        $ipFailures = (int) Db::value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at >= ?', [$request->ip, $since]);
        if ($ipFailures >= self::IP_MAX_FAILURES) {
            AuditLog::record($request, null, 'login_rate_limited', details: ['username' => $username]);
            throw new HttpError(429, 'rate_limited', 'Too many sign-in attempts from this computer. Wait 15 minutes and try again.');
        }

        $generic = new HttpError(401, 'invalid_credentials', 'The username or password is incorrect.');
        $u = $username === '' ? null : Db::one('SELECT * FROM users WHERE username = ?', [$username]);

        if ($u === null) {
            password_hash($password, PASSWORD_DEFAULT);  // spend the same time as a real check, so usernames can't be probed by timing
            self::recordAttempt($request, $username, false);
            throw $generic;
        }
        if ($u['locked_until'] !== null && strtotime($u['locked_until']) > time()) {
            self::recordAttempt($request, $username, false);
            $mins = (int) ceil((strtotime($u['locked_until']) - time()) / 60);
            throw new HttpError(423, 'locked', "This account is locked after too many wrong passwords. Try again in $mins minute(s), or ask an administrator to unlock it.");
        }
        if (!password_verify($password, $u['password_hash']) || !$u['is_active']) {
            self::recordAttempt($request, $username, false);
            if ($u['is_active']) {
                $fails = (int) $u['failed_logins'] + 1;
                if ($fails >= Settings::int('lockout_attempts')) {
                    Db::exec('UPDATE users SET failed_logins = 0, locked_until = ? WHERE id = ?', [date('Y-m-d H:i:s', time() + Settings::int('lockout_minutes') * 60), $u['id']]);
                    AuditLog::record($request, (int) $u['id'], 'account_locked', 'user', (int) $u['id']);
                } else {
                    Db::exec('UPDATE users SET failed_logins = ? WHERE id = ?', [$fails, $u['id']]);
                }
            }
            throw $generic;
        }

        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [Passwords::hash($password), $u['id']]);
        }
        Db::exec('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?', [$u['id']]);
        self::recordAttempt($request, $username, true);
        Session::login((int) $u['id']);
        self::$loaded = false;
        AuditLog::record($request, (int) $u['id'], 'login');
        return self::user();
    }

    private static function recordAttempt(Request $request, string $username, bool $success): void
    {
        Db::insert('login_attempts', ['ip' => $request->ip, 'username' => mb_substr($username, 0, 100), 'success' => (int) $success]);
    }

    /** Public view of a user record (never includes the hash). */
    public static function present(array $u): array
    {
        return [
            'id' => (int) $u['id'],
            'username' => $u['username'],
            'displayName' => $u['display_name'],
            'role' => $u['role'],
            'theme' => $u['theme'],
            'mustChangePassword' => (bool) $u['must_change_password'],
            'lastLoginAt' => $u['last_login_at'],
        ];
    }
}
