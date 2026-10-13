<?php

declare(strict_types=1);

namespace TransformerX\App\Services;

use TransformerX\App\AuditLog;
use TransformerX\App\Auth\Passwords;
use TransformerX\App\Db;
use TransformerX\App\Http\HttpError;
use TransformerX\App\Http\Request;

/** Admin user management. There is no self-registration. */
final class UserService
{
    public function list(): array
    {
        return array_map([self::class, 'present'], Db::all(
            'SELECT u.*, (SELECT COUNT(*) FROM conversions c WHERE c.user_id = u.id) AS conversions,
                    (SELECT MAX(created_at) FROM imports i WHERE i.user_id = u.id) AS last_import_at
               FROM users u ORDER BY u.is_active DESC, u.username',
        ));
    }

    /** @return array{user: array, temporaryPassword: string} */
    public function create(Request $request, array $admin): array
    {
        $username = $request->str('username', 50);
        $displayName = $request->str('displayName', 100);
        $role = $request->str('role', 10);
        $fields = [];
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            $fields['username'] = 'Use 3–50 letters, digits, dots, dashes or underscores.';
        } elseif (Db::value('SELECT 1 FROM users WHERE username = ?', [$username])) {
            $fields['username'] = 'This username is already taken.';
        }
        if ($displayName === '') {
            $fields['displayName'] = 'Enter the person\'s name.';
        }
        if (!in_array($role, ['admin', 'user'], true)) {
            $fields['role'] = 'Choose a role.';
        }
        if ($fields !== []) {
            throw HttpError::validation($fields);
        }
        $temp = Passwords::temporary();
        $id = Db::insert('users', [
            'username' => $username,
            'display_name' => $displayName,
            'password_hash' => Passwords::hash($temp),
            'role' => $role,
            'must_change_password' => 1,
            'created_by' => $admin['id'],
        ]);
        AuditLog::record($request, (int) $admin['id'], 'user_created', 'user', $id, ['username' => $username, 'role' => $role]);
        return ['user' => $this->get($id), 'temporaryPassword' => $temp];
    }

    public function update(int $id, Request $request, array $admin): array
    {
        $target = $this->row($id);
        $data = $request->json();
        $changes = [];

        if (array_key_exists('displayName', $data)) {
            $name = $request->str('displayName', 100);
            if ($name === '') {
                throw HttpError::validation(['displayName' => 'Enter the person\'s name.']);
            }
            $changes['display_name'] = $name;
        }
        if (array_key_exists('role', $data)) {
            $role = $request->str('role', 10);
            if (!in_array($role, ['admin', 'user'], true)) {
                throw HttpError::validation(['role' => 'Choose a role.']);
            }
            if ($role !== 'admin' && $target['role'] === 'admin') {
                $this->assertNotLastAdmin($id, (int) $admin['id'], 'remove the admin role from');
            }
            $changes['role'] = $role;
        }
        if (($active = $request->bool('isActive')) !== null) {
            if (!$active) {
                $this->assertNotLastAdmin($id, (int) $admin['id'], 'deactivate');
            }
            $changes['is_active'] = (int) $active;
        }
        if ($changes === []) {
            return $this->get($id);
        }
        $set = implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($changes)));
        Db::exec("UPDATE users SET $set WHERE id = ?", [...array_values($changes), $id]);
        AuditLog::record($request, (int) $admin['id'], 'user_updated', 'user', $id, $changes);
        return $this->get($id);
    }

    /** @return array{user: array, temporaryPassword: string} */
    public function resetPassword(int $id, Request $request, array $admin): array
    {
        $this->row($id);
        $temp = Passwords::temporary();
        Db::exec('UPDATE users SET password_hash = ?, must_change_password = 1, failed_logins = 0, locked_until = NULL WHERE id = ?', [Passwords::hash($temp), $id]);
        AuditLog::record($request, (int) $admin['id'], 'user_password_reset', 'user', $id);
        return ['user' => $this->get($id), 'temporaryPassword' => $temp];
    }

    public function unlock(int $id, Request $request, array $admin): array
    {
        $this->row($id);
        Db::exec('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$id]);
        AuditLog::record($request, (int) $admin['id'], 'user_unlocked', 'user', $id);
        return $this->get($id);
    }

    public function get(int $id): array
    {
        $u = Db::one(
            'SELECT u.*, (SELECT COUNT(*) FROM conversions c WHERE c.user_id = u.id) AS conversions,
                    (SELECT MAX(created_at) FROM imports i WHERE i.user_id = u.id) AS last_import_at
               FROM users u WHERE u.id = ?',
            [$id],
        );
        if ($u === null) {
            throw HttpError::notFound('User not found.');
        }
        return self::present($u);
    }

    public static function present(array $u): array
    {
        return [
            'id' => (int) $u['id'],
            'username' => $u['username'],
            'displayName' => $u['display_name'],
            'role' => $u['role'],
            'isActive' => (bool) $u['is_active'],
            'mustChangePassword' => (bool) $u['must_change_password'],
            'locked' => $u['locked_until'] !== null && strtotime($u['locked_until']) > time(),
            'lockedUntil' => $u['locked_until'],
            'lastLoginAt' => $u['last_login_at'],
            'lastImportAt' => $u['last_import_at'] ?? null,
            'conversions' => (int) ($u['conversions'] ?? 0),
            'createdAt' => $u['created_at'],
        ];
    }

    private function row(int $id): array
    {
        $u = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        if ($u === null) {
            throw HttpError::notFound('User not found.');
        }
        return $u;
    }

    private function assertNotLastAdmin(int $targetId, int $adminId, string $verb): void
    {
        if ($targetId === $adminId) {
            throw HttpError::conflict("You can't $verb your own account. Ask another administrator.");
        }
        $others = (int) Db::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?", [$targetId]);
        if ($others === 0) {
            throw HttpError::conflict("You can't $verb the last active administrator.");
        }
    }
}
