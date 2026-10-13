<?php

declare(strict_types=1);

namespace TransformerX\App;

use TransformerX\App\Auth\Auth;
use TransformerX\App\Auth\Passwords;
use TransformerX\App\Http\HttpError;
use TransformerX\App\Http\Request;
use TransformerX\App\Http\Response;
use TransformerX\App\Http\Router;
use TransformerX\App\Security\Csrf;
use TransformerX\App\Security\SecurityHeaders;
use TransformerX\App\Security\Session;
use TransformerX\App\Services\HistoryService;
use TransformerX\App\Services\Housekeeping;
use TransformerX\App\Services\ImportService;
use TransformerX\App\Services\StatsService;
use TransformerX\App\Services\UserService;
use TransformerX\Engine\Schema;

/** Front controller: serves the single-page shell and the JSON API under /api. */
final class App
{
    public static function run(): void
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);
        date_default_timezone_set((string) Config::get('timezone', 'UTC'));

        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/', 2)), '/');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        $request = Request::fromGlobals($basePath);
        $isApi = str_starts_with($request->path, '/api/');
        SecurityHeaders::send($https, $isApi);
        if ($isApi) {
            header('X-App-Version: ' . Shell::version());
        }

        try {
            if (!$isApi) {
                if ($request->method !== 'GET' && $request->method !== 'HEAD') {
                    throw new HttpError(405, 'method_not_allowed', 'Method not allowed.');
                }
                if (str_starts_with($request->path, '/assets/')) {
                    throw HttpError::notFound();
                }
                Shell::response($basePath)->send();
                return;
            }
            Session::start($basePath, $https);
            Housekeeping::maybeRun();
            Csrf::verify($request);
            $response = self::router()->dispatch($request);
        } catch (HttpError $e) {
            $response = Response::error($e);
        } catch (\Throwable $e) {
            $ref = Log::error('unhandled', $e, ['path' => $request->path, 'method' => $request->method]);
            $response = Response::error(new HttpError(500, 'server_error', "Something went wrong on the server. If it happens again, tell an administrator (reference $ref)."));
        }
        $response->send();
    }

    private static function router(): Router
    {
        $r = new Router();
        $imports = new ImportService();

        // ---- session
        $r->add('GET', '/api/auth/me', static function (Request $req) {
            $u = Auth::user();
            return Response::json([
                'user' => $u === null ? null : Auth::present($u),
                'csrfToken' => Csrf::token(),
                'sessionExpired' => Session::takeExpiredFlag(),
                'idleMinutes' => Settings::int('session_idle_minutes'),
            ]);
        });
        $r->add('POST', '/api/auth/login', static function (Request $req) {
            $u = Auth::login($req, $req->str('username', 100), (string) ($req->json()['password'] ?? ''));
            return Response::json(['user' => Auth::present($u), 'csrfToken' => Csrf::token()]);
        });
        $r->add('POST', '/api/auth/logout', static function (Request $req) {
            $u = Auth::user();
            if ($u !== null) {
                AuditLog::record($req, (int) $u['id'], 'logout');
            }
            Session::destroy();
            return Response::json(['ok' => true]);
        });
        $r->add('POST', '/api/auth/change-password', static function (Request $req) {
            $u = Auth::user() ?? throw HttpError::unauthorized();
            $current = (string) ($req->json()['currentPassword'] ?? '');
            $new = (string) ($req->json()['newPassword'] ?? '');
            if (!password_verify($current, $u['password_hash'])) {
                throw HttpError::validation(['currentPassword' => 'This is not your current password.']);
            }
            if (($problem = Passwords::problem($new, $u['username'])) !== null) {
                throw HttpError::validation(['newPassword' => $problem]);
            }
            if (password_verify($new, $u['password_hash'])) {
                throw HttpError::validation(['newPassword' => 'Choose a password different from the current one.']);
            }
            Db::exec('UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = NOW() WHERE id = ?', [Passwords::hash($new), $u['id']]);
            Session::login((int) $u['id']);   // new session ID after a credential change
            AuditLog::record($req, (int) $u['id'], 'password_changed');
            return Response::json(['ok' => true, 'csrfToken' => Csrf::token()]);
        });
        $r->add('PATCH', '/api/me', static function (Request $req) {
            $u = Auth::require();
            $theme = $req->str('theme', 10);
            if (!in_array($theme, ['system', 'light', 'dark'], true)) {
                throw HttpError::validation(['theme' => 'Choose system, light or dark.']);
            }
            Db::exec('UPDATE users SET theme = ? WHERE id = ?', [$theme, $u['id']]);
            return Response::json(['ok' => true]);
        });

        // ---- dashboard, template
        $r->add('GET', '/api/dashboard', static fn () => Response::json((new StatsService())->dashboard(Auth::require())));
        $r->add('GET', '/api/format', static function () {
            Auth::require();
            return Response::json([
                'inputColumns' => Schema::INPUT_COLUMNS,
                'numericColumns' => Schema::NUMERIC_COLUMNS,
                'outputColumns' => Schema::outputColumns(),
                'attributeFields' => Schema::ATTRIBUTE_FIELDS,
                'maxUploadMb' => Settings::int('max_upload_mb'),
                'maxRows' => Settings::int('max_rows'),
                'previewExpiryHours' => Settings::int('preview_expiry_hours'),
                'engineVersion' => Schema::ENGINE_VERSION,
            ]);
        });
        $r->add('GET', '/api/template', static function () {
            Auth::require();
            $path = Config::storage('tmp') . '/tpl_' . bin2hex(random_bytes(8)) . '.xlsx';
            $w = new \OpenSpout\Writer\XLSX\Writer();
            $w->openToFile($path);
            $w->getCurrentSheet()->setName('Sheet1');
            $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(Schema::INPUT_COLUMNS));
            $w->close();
            return Response::download($path, 'Product upload template.xlsx', true);
        });

        // ---- imports
        $r->add('POST', '/api/imports', static fn (Request $req) => Response::json($imports->upload($req, Auth::require()), 201));
        $r->add('GET', '/api/imports/{id}', static function (Request $req) use ($imports) {
            Auth::require();
            return Response::json($imports->present($imports->find($req->param('id'))));
        });
        $r->add('GET', '/api/imports/{id}/rows', static function (Request $req) use ($imports) {
            Auth::require();
            return Response::json($imports->rows($req->param('id'), $req));
        });
        $r->add('GET', '/api/imports/{id}/rows/{row}', static function (Request $req) use ($imports) {
            Auth::require();
            return Response::json($imports->rowDetail($req->param('id'), $req->param('row')));
        });
        $r->add('POST', '/api/imports/{id}/convert', static fn (Request $req) => $imports->convert($req->param('id'), $req, Auth::require()));
        $r->add('POST', '/api/imports/{id}/cancel', static fn (Request $req) => Response::json($imports->cancel($req->param('id'), $req, Auth::require())));

        // ---- history & statistics (own data; admins see all)
        $r->add('GET', '/api/history', static function (Request $req) {
            $u = Auth::require();
            return Response::json((new HistoryService())->list($req, $u, $u['role'] === 'admin'));
        });
        $r->add('GET', '/api/stats', static function (Request $req) {
            $u = Auth::require();
            return Response::json((new StatsService())->stats($req, $u, $u['role'] === 'admin'));
        });

        // ---- administration
        $users = new UserService();
        $r->add('GET', '/api/admin/users', static function () use ($users) {
            Auth::require('admin');
            return Response::json(['items' => $users->list()]);
        });
        $r->add('POST', '/api/admin/users', static fn (Request $req) => Response::json($users->create($req, Auth::require('admin')), 201));
        $r->add('PATCH', '/api/admin/users/{id}', static fn (Request $req) => Response::json($users->update($req->param('id'), $req, Auth::require('admin'))));
        $r->add('POST', '/api/admin/users/{id}/reset-password', static fn (Request $req) => Response::json($users->resetPassword($req->param('id'), $req, Auth::require('admin'))));
        $r->add('POST', '/api/admin/users/{id}/unlock', static fn (Request $req) => Response::json($users->unlock($req->param('id'), $req, Auth::require('admin'))));
        $r->add('GET', '/api/admin/audit', static function (Request $req) {
            Auth::require('admin');
            return Response::json(self::audit($req));
        });
        $r->add('GET', '/api/admin/settings', static function () {
            Auth::require('admin');
            return Response::json(['settings' => Settings::all()]);
        });
        $r->add('PUT', '/api/admin/settings', static function (Request $req) {
            $admin = Auth::require('admin');
            $data = $req->json();
            $fields = [];
            $changes = [];
            foreach (Settings::DEFINITIONS as $name => [, $min, $max]) {
                if (!array_key_exists($name, $data)) {
                    continue;
                }
                $v = $data[$name];
                if (!is_int($v) || $v < $min || $v > $max) {
                    $fields[$name] = "Enter a whole number from $min to $max.";
                    continue;
                }
                $changes[$name] = $v;
            }
            if ($fields !== []) {
                throw HttpError::validation($fields);
            }
            foreach ($changes as $name => $v) {
                Settings::set($name, $v, (int) $admin['id']);
            }
            AuditLog::record($req, (int) $admin['id'], 'settings_changed', details: $changes);
            return Response::json(['settings' => Settings::all()]);
        });

        return $r;
    }

    private static function audit(Request $req): array
    {
        $page = $req->queryInt('page', 1, 1, 100000);
        $size = $req->queryInt('size', 50, 10, 200);
        $where = ['1=1'];
        $params = [];
        if (($uid = $req->queryInt('user', 0, 0, PHP_INT_MAX)) > 0) {
            $where[] = 'a.user_id = ?';
            $params[] = $uid;
        }
        if (($action = $req->queryStr('action', 50)) !== '') {
            $where[] = 'a.action = ?';
            $params[] = $action;
        }
        $w = implode(' AND ', $where);
        $total = (int) Db::value("SELECT COUNT(*) FROM audit_log a WHERE $w", $params);
        $offset = ($page - 1) * $size;
        $items = Db::all(
            "SELECT a.*, u.username FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE $w ORDER BY a.id DESC LIMIT $size OFFSET $offset",
            $params,
        );
        return [
            'page' => $page,
            'size' => $size,
            'total' => $total,
            'actions' => array_column(Db::all('SELECT DISTINCT action FROM audit_log ORDER BY action'), 'action'),
            'items' => array_map(static fn ($a) => [
                'id' => (int) $a['id'], 'at' => $a['created_at'], 'username' => $a['username'], 'action' => $a['action'],
                'targetType' => $a['target_type'], 'targetId' => $a['target_id'] === null ? null : (int) $a['target_id'],
                'details' => $a['details_json'] === null ? null : json_decode($a['details_json'], true),
                'ip' => $a['ip'], 'userAgent' => $a['user_agent'],
            ], $items),
        ];
    }
}
