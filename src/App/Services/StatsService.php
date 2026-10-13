<?php

declare(strict_types=1);

namespace TransformerX\App\Services;

use TransformerX\App\Db;
use TransformerX\App\Http\Request;

/** Aggregates the permanent import/conversion records for the Statistics page and the dashboard. */
final class StatsService
{
    public function stats(Request $request, array $user, bool $isAdmin): array
    {
        $to = $request->queryDate('to') ?? date('Y-m-d');
        $from = $request->queryDate('from') ?? date('Y-m-d', strtotime("$to -29 days"));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $userFilter = $isAdmin ? $request->queryInt('user', 0, 0, PHP_INT_MAX) : (int) $user['id'];
        $range = ["$from 00:00:00", "$to 23:59:59"];

        $iw = 'i.created_at BETWEEN ? AND ?' . ($userFilter > 0 ? ' AND i.user_id = ?' : '');
        $cw = 'c.created_at BETWEEN ? AND ?' . ($userFilter > 0 ? ' AND c.user_id = ?' : '');
        $p = $userFilter > 0 ? [...$range, $userFilter] : $range;

        $imports = Db::one(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'converted'), 0) AS converted,
                    COALESCE(SUM(status = 'rejected'), 0) AS rejected,
                    COALESCE(SUM(status = 'blocked'), 0) AS blocked,
                    COALESCE(SUM(status IN ('cancelled','expired')), 0) AS abandoned,
                    COALESCE(SUM(status = 'ready'), 0) AS open,
                    COALESCE(SUM(data_rows), 0) AS rows_read,
                    COALESCE(SUM(error_count), 0) AS errors,
                    COALESCE(SUM(warning_count), 0) AS warnings
               FROM imports i WHERE $iw",
            $p,
        );
        $conversions = Db::one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(rows_out), 0) AS rows_out, COALESCE(SUM(total_cents), 0) AS total_cents,
                    COALESCE(SUM(zero_price_rows), 0) AS zero_price, COALESCE(SUM(blank_price_rows), 0) AS blank_price,
                    COALESCE(SUM(attributes_dropped_rows), 0) AS attributes_dropped, COALESCE(AVG(duration_ms), 0) AS avg_ms,
                    COUNT(DISTINCT user_id) AS users
               FROM conversions c WHERE $cw",
            $p,
        );

        $daily = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
            $daily[$d] = ['date' => $d, 'imports' => 0, 'conversions' => 0, 'rows' => 0];
        }
        foreach (Db::all("SELECT DATE(i.created_at) AS d, COUNT(*) AS n FROM imports i WHERE $iw GROUP BY DATE(i.created_at)", $p) as $r) {
            if (isset($daily[$r['d']])) {
                $daily[$r['d']]['imports'] = (int) $r['n'];
            }
        }
        foreach (Db::all("SELECT DATE(c.created_at) AS d, COUNT(*) AS n, SUM(rows_out) AS r FROM conversions c WHERE $cw GROUP BY DATE(c.created_at)", $p) as $r) {
            if (isset($daily[$r['d']])) {
                $daily[$r['d']]['conversions'] = (int) $r['n'];
                $daily[$r['d']]['rows'] = (int) $r['r'];
            }
        }

        $breakdown = static fn (string $dimension) => array_map(static fn ($r) => [
            'name' => $r['name'], 'rows' => (int) $r['n'], 'totalCents' => (int) $r['cents'], 'zeroPriceRows' => (int) $r['zero'],
        ], Db::all(
            "SELECT b.name, SUM(b.row_count) AS n, SUM(b.total_cents) AS cents, SUM(b.zero_price_rows) AS zero
               FROM conversion_breakdown b JOIN conversions c ON c.id = b.conversion_id
              WHERE b.dimension = ? AND $cw GROUP BY b.name ORDER BY n DESC LIMIT 20",
            [$dimension, ...$p],
        ));

        $issues = array_map(static fn ($r) => [
            'severity' => $r['severity'], 'code' => $r['code'], 'count' => (int) $r['n'], 'imports' => (int) $r['imports'],
        ], Db::all(
            "SELECT ic.severity, ic.code, SUM(ic.count) AS n, COUNT(DISTINCT ic.import_id) AS imports
               FROM import_issue_counts ic JOIN imports i ON i.id = ic.import_id
              WHERE $iw GROUP BY ic.severity, ic.code ORDER BY n DESC LIMIT 20",
            $p,
        ));

        $byUser = !$isAdmin ? [] : array_map(static fn ($r) => [
            'userId' => (int) $r['id'], 'username' => $r['username'], 'displayName' => $r['display_name'],
            'imports' => (int) $r['imports'], 'conversions' => (int) $r['conversions'], 'rows' => (int) $r['rows_out'],
        ], Db::all(
            "SELECT u.id, u.username, u.display_name,
                    (SELECT COUNT(*) FROM imports i WHERE i.user_id = u.id AND i.created_at BETWEEN ? AND ?) AS imports,
                    (SELECT COUNT(*) FROM conversions c WHERE c.user_id = u.id AND c.created_at BETWEEN ? AND ?) AS conversions,
                    (SELECT COALESCE(SUM(rows_out), 0) FROM conversions c WHERE c.user_id = u.id AND c.created_at BETWEEN ? AND ?) AS rows_out
               FROM users u
              HAVING imports > 0 OR conversions > 0
              ORDER BY conversions DESC, imports DESC",
            [...$range, ...$range, ...$range],
        ));

        $allTime = Db::one(
            'SELECT COUNT(*) AS conversions, COALESCE(SUM(rows_out), 0) AS rows_out, MIN(created_at) AS first_at FROM conversions c'
                . ($userFilter > 0 ? ' WHERE c.user_id = ?' : ''),
            $userFilter > 0 ? [$userFilter] : [],
        );

        return [
            'from' => $from,
            'to' => $to,
            'userId' => $userFilter ?: null,
            'imports' => array_map('intval', $imports),
            'conversions' => [
                'total' => (int) $conversions['total'],
                'rowsOut' => (int) $conversions['rows_out'],
                'totalCents' => (int) $conversions['total_cents'],
                'zeroPriceRows' => (int) $conversions['zero_price'],
                'blankPriceRows' => (int) $conversions['blank_price'],
                'attributesDroppedRows' => (int) $conversions['attributes_dropped'],
                'avgDurationMs' => (int) round((float) $conversions['avg_ms']),
                'users' => (int) $conversions['users'],
            ],
            'daily' => array_values($daily),
            'byCategory' => $breakdown('category'),
            'byProductType' => $breakdown('product_type'),
            'issues' => $issues,
            'byUser' => $byUser,
            'allTime' => ['conversions' => (int) $allTime['conversions'], 'rowsOut' => (int) $allTime['rows_out'], 'firstAt' => $allTime['first_at']],
        ];
    }

    public function dashboard(array $user): array
    {
        $uid = (int) $user['id'];
        $monthStart = date('Y-m-01 00:00:00');
        $mine = Db::one(
            'SELECT COUNT(*) AS conversions, COALESCE(SUM(rows_out), 0) AS rows_out, COALESCE(SUM(total_cents), 0) AS cents
               FROM conversions WHERE user_id = ? AND created_at >= ?',
            [$uid, $monthStart],
        );
        $open = Db::all(
            "SELECT id, original_filename, status, data_rows, error_count, warning_count, created_at
               FROM imports WHERE user_id = ? AND status IN ('ready','blocked') ORDER BY created_at DESC LIMIT 5",
            [$uid],
        );
        $recent = Db::all(
            'SELECT i.id, i.original_filename, i.status, i.data_rows, i.created_at, c.output_filename, c.rows_out, c.total_cents
               FROM imports i LEFT JOIN conversions c ON c.import_id = i.id
              WHERE i.user_id = ? ORDER BY i.created_at DESC, i.id DESC LIMIT 6',
            [$uid],
        );
        $map = static fn (array $r) => [
            'id' => (int) $r['id'], 'filename' => $r['original_filename'], 'status' => $r['status'],
            'rows' => (int) $r['data_rows'], 'createdAt' => $r['created_at'],
            'errors' => isset($r['error_count']) ? (int) $r['error_count'] : null,
            'warnings' => isset($r['warning_count']) ? (int) $r['warning_count'] : null,
            'outputFilename' => $r['output_filename'] ?? null,
            'rowsOut' => isset($r['rows_out']) ? (int) $r['rows_out'] : null,
            'totalCents' => isset($r['total_cents']) ? (int) $r['total_cents'] : null,
        ];
        return [
            'month' => ['conversions' => (int) $mine['conversions'], 'rowsOut' => (int) $mine['rows_out'], 'totalCents' => (int) $mine['cents']],
            'open' => array_map($map, $open),
            'recent' => array_map($map, $recent),
        ];
    }
}
