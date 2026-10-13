<?php

declare(strict_types=1);

namespace TransformerX\Tests\App;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TransformerX\App\Auth\Passwords;
use TransformerX\App\Db;
use TransformerX\Tests\Tools\XlsxCells;

/**
 * End-to-end tests against the running app (http://localhost/transformer-x by default; override with TX_BASE_URL).
 * Creates throwaway users tx_test_* and removes them and everything they created afterwards.
 */
#[Group('http')]
final class HttpFlowTest extends TestCase
{
    private const PASSWORD = 'correct horse battery';
    private static string $base;
    /** @var array<string, int> */
    private static array $users = [];

    public static function setUpBeforeClass(): void
    {
        self::$base = getenv('TX_BASE_URL') ?: 'http://localhost/transformer-x';
        self::cleanup();
        foreach ([['tx_test_admin', 'admin', 0], ['tx_test_user', 'user', 0], ['tx_test_other', 'user', 0], ['tx_test_new', 'user', 1], ['tx_test_lock', 'user', 0]] as [$name, $role, $mustChange]) {
            self::$users[$name] = Db::insert('users', [
                'username' => $name, 'display_name' => "Test $name", 'password_hash' => Passwords::hash(self::PASSWORD),
                'role' => $role, 'must_change_password' => $mustChange,
            ]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanup();
    }

    private static function cleanup(): void
    {
        $ids = array_column(Db::all("SELECT id FROM users WHERE username LIKE 'tx\\_test\\_%'"), 'id');
        if ($ids === []) {
            Db::exec("DELETE FROM login_attempts WHERE username LIKE 'tx\\_test\\_%'");
            return;
        }
        $in = implode(',', array_map('intval', $ids));
        Db::exec("DELETE b FROM conversion_breakdown b JOIN conversions c ON c.id = b.conversion_id WHERE c.user_id IN ($in)");
        Db::exec("DELETE FROM conversions WHERE user_id IN ($in)");
        Db::exec("DELETE FROM imports WHERE user_id IN ($in)");
        Db::exec("DELETE FROM audit_log WHERE user_id IN ($in) OR (target_type = 'user' AND target_id IN ($in))");
        Db::exec("DELETE FROM login_attempts WHERE username LIKE 'tx\\_test\\_%'");
        Db::exec("DELETE FROM users WHERE id IN ($in)");
    }

    public function testAnonymousAccessAndCsrf(): void
    {
        $c = new Client(self::$base);
        self::assertSame(401, $c->get('/api/dashboard')[0]);
        // A write without the CSRF token is refused even before credentials are checked.
        [$status, $body] = $c->post('/api/auth/login', ['username' => 'tx_test_user', 'password' => self::PASSWORD], csrf: false);
        self::assertSame([403, 'csrf'], [$status, $body['error']['code']]);
        [$status, $body] = $c->post('/api/auth/login', ['username' => 'tx_test_user', 'password' => 'wrong password']);
        self::assertSame(401, $status);
        self::assertSame('The username or password is incorrect.', $body['error']['message']);
        [$status] = $c->post('/api/auth/login', ['username' => 'nobody_here', 'password' => 'x']);
        self::assertSame(401, $status);
        self::assertSame(200, $c->login('tx_test_user', self::PASSWORD));
        self::assertSame(200, $c->get('/api/dashboard')[0]);
        // Missing token after sign-in
        [$status, $body] = $c->post('/api/imports/1/cancel', [], csrf: false);
        self::assertSame([403, 'csrf'], [$status, $body['error']['code']]);
    }

    public function testLockoutAfterRepeatedWrongPasswords(): void
    {
        $c = new Client(self::$base);
        for ($i = 0; $i < 5; $i++) {
            $c->post('/api/auth/login', ['username' => 'tx_test_lock', 'password' => "wrong $i"]);
        }
        [$status, $body] = $c->post('/api/auth/login', ['username' => 'tx_test_lock', 'password' => self::PASSWORD]);
        self::assertSame(423, $status, json_encode($body));
        // An admin can unlock.
        $admin = new Client(self::$base);
        $admin->login('tx_test_admin', self::PASSWORD);
        self::assertSame(200, $admin->post('/api/admin/users/' . self::$users['tx_test_lock'] . '/unlock', [])[0]);
        self::assertSame(200, $c->login('tx_test_lock', self::PASSWORD));
    }

    public function testForcedPasswordChange(): void
    {
        $c = new Client(self::$base);
        self::assertSame(200, $c->login('tx_test_new', self::PASSWORD));
        [$status, $body] = $c->get('/api/dashboard');
        self::assertSame(403, $status);
        self::assertSame('password_change_required', $body['error']['code']);
        [$status, $body] = $c->post('/api/auth/change-password', ['currentPassword' => self::PASSWORD, 'newPassword' => 'short']);
        self::assertSame(422, $status);
        self::assertArrayHasKey('newPassword', $body['error']['fields']);
        [$status, $body] = $c->post('/api/auth/change-password', ['currentPassword' => self::PASSWORD, 'newPassword' => 'emerald parcel sapphire']);
        self::assertSame(200, $status, json_encode($body));
        $c->csrf = $body['csrfToken'];
        self::assertSame(200, $c->get('/api/dashboard')[0]);
    }

    public function testRolesAreEnforced(): void
    {
        $c = new Client(self::$base);
        $c->login('tx_test_user', self::PASSWORD);
        self::assertSame(403, $c->get('/api/admin/users')[0]);
        self::assertSame(403, $c->get('/api/admin/audit')[0]);
        self::assertSame(403, $c->put('/api/admin/settings', ['max_rows' => 100])[0]);
        self::assertSame(403, $c->post('/api/admin/users', ['username' => 'tx_test_x', 'displayName' => 'X', 'role' => 'admin'])[0]);
    }

    public function testFullConversionMatchesAccessAndLeavesNoFiles(): void
    {
        $fixtures = dirname(__DIR__) . '/fixtures';
        $tmpBefore = self::tmpFiles();
        $c = new Client(self::$base);
        $c->login('tx_test_user', self::PASSWORD);

        [$status, $imp] = $c->upload('/api/imports', "$fixtures/input/sample_v5.xlsx", 'Full Product Upload V5 sheet.xlsx');
        self::assertSame(201, $status, json_encode($imp));
        self::assertSame('ready', $imp['status']);
        self::assertSame(4259, $imp['summary']['rows']);
        self::assertSame(2009, $imp['summary']['zeroPrice']);
        self::assertSame(0, $imp['summary']['errorCount']);
        self::assertSame($tmpBefore, self::tmpFiles(), 'the upload must not stay on disk');
        $id = $imp['id'];

        [$status, $page] = $c->get("/api/imports/$id/rows?view=output&page=2&size=50&status=warning");
        self::assertSame(200, $status);
        self::assertCount(50, $page['rows']);
        self::assertCount(111, $page['rows'][0]['cells']);
        self::assertSame('warning', $page['rows'][0]['status']);

        [$status, $row] = $c->get("/api/imports/$id/rows/2");
        self::assertSame(200, $status);
        self::assertSame('$5.98', $row['conversion']['price']['text']);
        self::assertSame('1000A.8', $row['conversion']['output']['SKU']);

        // Another user cannot see it; an admin can.
        $other = new Client(self::$base);
        $other->login('tx_test_other', self::PASSWORD);
        self::assertSame(404, $other->get("/api/imports/$id")[0]);
        self::assertSame(404, $other->post("/api/imports/$id/convert", [])[0]);
        $admin = new Client(self::$base);
        $admin->login('tx_test_admin', self::PASSWORD);
        self::assertSame(200, $admin->get("/api/imports/$id")[0]);
        self::assertSame(403, $admin->post("/api/imports/$id/convert", [])[0], 'only the uploader converts');

        [$status, $file, $headers] = $c->postRaw("/api/imports/$id/convert");
        self::assertSame(200, $status);
        self::assertMatchesRegularExpression('/^\d{8}_\d{6}\.xlsx$/', $headers['x-filename'] ?? '');
        $out = tempnam(sys_get_temp_dir(), 'tx') . '.xlsx';
        file_put_contents($out, $file);
        try {
            self::assertSame([], XlsxCells::diff("$fixtures/golden/golden_sample_v5.xlsx", $out));
        } finally {
            @unlink($out);
        }
        self::assertSame($tmpBefore, self::tmpFiles(), 'the output must not stay on disk');
        self::assertSame(0, (int) Db::value('SELECT COUNT(*) FROM import_rows WHERE import_id = ?', [$id]), 'staged rows are deleted');

        [$status, $after] = $c->get("/api/imports/$id");
        self::assertSame('converted', $after['status']);
        self::assertSame(4259, $after['conversion']['rowsOut']);
        self::assertSame(2009, $after['conversion']['zeroPriceRows']);
        self::assertSame(409, $c->post("/api/imports/$id/convert", [])[0], 'cannot convert twice');

        [, $history] = $c->get('/api/history');
        self::assertSame($id, $history['items'][0]['id']);
        [, $stats] = $c->get('/api/stats');
        self::assertSame(1, $stats['conversions']['total']);
        self::assertSame(4259, $stats['conversions']['rowsOut']);
        self::assertNotEmpty($stats['byCategory']);
    }

    public function testBlockedAndRejectedUploads(): void
    {
        $fixtures = dirname(__DIR__) . '/fixtures';
        $c = new Client(self::$base);
        $c->login('tx_test_other', self::PASSWORD);

        [$status, $imp] = $c->upload('/api/imports', "$fixtures/input/edge_cases_2.xlsx", 'edge.xlsx');
        self::assertSame(201, $status);
        self::assertSame('blocked', $imp['status']);
        self::assertSame(2, $imp['summary']['errorsByCode']['text_in_number_column']);
        self::assertSame(409, $c->post("/api/imports/{$imp['id']}/convert", [])[0]);
        [$status, $rows] = $c->get("/api/imports/{$imp['id']}/rows?status=error");
        self::assertSame(1, $rows['total']);
        self::assertSame(200, $c->post("/api/imports/{$imp['id']}/cancel", [])[0]);
        self::assertSame(409, $c->get("/api/imports/{$imp['id']}/rows")[0]);

        $csv = tempnam(sys_get_temp_dir(), 'tx');
        file_put_contents($csv, "Product Category,Name\n");
        [$status, $body] = $c->upload('/api/imports', $csv, 'products.xlsx');
        @unlink($csv);
        self::assertSame(400, $status);
        self::assertSame('This file is not an Excel workbook.', $body['error']['message']);

        [$status] = $c->upload('/api/imports', "$fixtures/input/edge_cases.xlsx", 'renamed.csv');
        self::assertSame(400, $status);
    }

    /** @return list<string> */
    private static function tmpFiles(): array
    {
        $files = array_map('basename', glob(dirname(__DIR__, 2) . '/storage/tmp/{up_,out_}*', GLOB_BRACE) ?: []);
        sort($files);
        return $files;
    }
}

/** Minimal HTTP client with a cookie jar and the CSRF token. */
final class Client
{
    public string $csrf = '';
    private string $jar;

    public function __construct(private readonly string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'txjar');
        $this->csrf = $this->get('/api/auth/me')[1]['csrfToken'];
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    public function login(string $user, string $password): int
    {
        [$status, $body] = $this->post('/api/auth/login', ['username' => $user, 'password' => $password]);
        if ($status === 200) {
            $this->csrf = $body['csrfToken'];
        }
        return $status;
    }

    public function get(string $path): array
    {
        return $this->json('GET', $path);
    }

    public function post(string $path, array $body, bool $csrf = true): array
    {
        return $this->json('POST', $path, $body, $csrf);
    }

    public function put(string $path, array $body): array
    {
        return $this->json('PUT', $path, $body);
    }

    public function upload(string $path, string $file, string $name): array
    {
        [$status, $raw] = $this->send('POST', $path, ['file' => new \CURLFile($file, 'application/octet-stream', $name)], true);
        return [$status, json_decode($raw, true)];
    }

    /** @return array{0: int, 1: string, 2: array<string, string>} */
    public function postRaw(string $path): array
    {
        return $this->send('POST', $path, null, true, true);
    }

    private function json(string $method, string $path, ?array $body = null, bool $csrf = true): array
    {
        [$status, $raw] = $this->send($method, $path, $body === null ? null : json_encode($body), $csrf);
        return [$status, json_decode($raw, true)];
    }

    private function send(string $method, string $path, mixed $body, bool $csrf, bool $withHeaders = false): array
    {
        $headers = [];
        $ch = curl_init($this->base . $path);
        $h = ['Accept: application/json'];
        if ($method !== 'GET' && $csrf) {
            $h[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        if (is_string($body)) {
            $h[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$headers) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $withHeaders ? [$status, $raw, $headers] : [$status, $raw];
    }
}
