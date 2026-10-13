<?php
// One-time setup: creates a dedicated MariaDB account with rights on the app database only,
// applies database/schema.sql, writes config/config.php and creates the first administrator.
//
// Usage:
//   php bin/install.php --admin=<username> [--admin-name="Full Name"] [--db-root-user=root] [--db-root-pass=]
//                       [--db-host=127.0.0.1] [--db-name=transformer_x] [--timezone=Asia/Karachi]
// Safe to re-run: existing tables, settings and users are kept; the config file is not overwritten.

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use TransformerX\App\Auth\Passwords;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$opt = getopt('', ['admin:', 'admin-name::', 'db-root-user::', 'db-root-pass::', 'db-host::', 'db-name::', 'timezone::']);
$adminUser = $opt['admin'] ?? null;
if (!is_string($adminUser) || !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $adminUser)) {
    fwrite(STDERR, "Give the first administrator's username: --admin=<3-50 letters/digits/._->\n");
    exit(1);
}
$host = $opt['db-host'] ?? '127.0.0.1';
$dbName = $opt['db-name'] ?? 'transformer_x';
$timezone = $opt['timezone'] ?? 'Asia/Karachi';
if (!preg_match('/^\w+$/', $dbName) || !in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
    fwrite(STDERR, "Invalid --db-name or --timezone.\n");
    exit(1);
}

$root = new PDO("mysql:host=$host;charset=utf8mb4", $opt['db-root-user'] ?? 'root', $opt['db-root-pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$configFile = dirname(__DIR__) . '/config/config.php';
if (!is_file($configFile)) {
    $appUser = 'tx_app';
    $appPass = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    foreach (['localhost', '127.0.0.1', '::1'] as $h) {
        $root->exec("CREATE USER IF NOT EXISTS '$appUser'@'$h' IDENTIFIED BY " . $root->quote($appPass));
        $root->exec("ALTER USER '$appUser'@'$h' IDENTIFIED BY " . $root->quote($appPass));
        $root->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `$dbName`.* TO '$appUser'@'$h'");
    }
    $root->exec('FLUSH PRIVILEGES');
    $config = "<?php\n// Created by bin/install.php. Keep this file private; it is never served over HTTP.\nreturn " . var_export([
        'db' => ['host' => $host, 'port' => 3306, 'name' => $dbName, 'user' => $appUser, 'pass' => $appPass],
        'timezone' => $timezone,
    ], true) . ";\n";
    file_put_contents($configFile, $config);
    echo "Wrote config/config.php (database account '$appUser', SELECT/INSERT/UPDATE/DELETE on $dbName only).\n";
} else {
    echo "config/config.php already exists; left unchanged.\n";
}

$root->exec("USE `$dbName`");
$sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
foreach (array_filter(array_map('trim', preg_split('/;\s*$/m', preg_replace('/^--.*$/m', '', $sql)))) as $statement) {
    $root->exec($statement);
}
echo "Schema applied.\n";

$exists = $root->prepare('SELECT id FROM users WHERE username = ?');
$exists->execute([$adminUser]);
if ($exists->fetchColumn()) {
    echo "User '$adminUser' already exists; not changed.\n";
    exit(0);
}
$temp = Passwords::temporary();
$st = $root->prepare("INSERT INTO users (username, display_name, password_hash, role, must_change_password) VALUES (?, ?, ?, 'admin', 1)");
$st->execute([$adminUser, $opt['admin-name'] ?? $adminUser, Passwords::hash($temp)]);
echo "\nAdministrator created.\n  Username:           $adminUser\n  Temporary password: $temp\n  (You will be asked to choose a new password at first sign-in.)\n";
