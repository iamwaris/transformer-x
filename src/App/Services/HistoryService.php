<?php

declare(strict_types=1);

namespace TransformerX\App\Services;

use TransformerX\App\Db;
use TransformerX\App\Http\Request;

/** Past imports and conversions. Users see their own; admins see everyone's (optionally filtered by user). */
final class HistoryService
{
    public function list(Request $request, array $user, bool $isAdmin): array
    {
        $page = $request->queryInt('page', 1, 1, 100000);
        $size = $request->queryInt('size', 25, 5, 100);
        [$where, $params] = self::filters($request, $user, $isAdmin);

        $total = (int) Db::value("SELECT COUNT(*) FROM imports i WHERE $where", $params);
        $offset = ($page - 1) * $size;
        $rows = Db::all(
            "SELECT i.id, i.original_filename, i.file_size, i.status, i.data_rows, i.error_count, i.warning_count,
                    i.created_at, i.closed_at, u.username, u.display_name,
                    c.output_filename, c.rows_out, c.total_cents, c.zero_price_rows, c.duration_ms, c.created_at AS converted_at
               FROM imports i
               JOIN users u ON u.id = i.user_id
               LEFT JOIN conversions c ON c.import_id = i.id
              WHERE $where
              ORDER BY i.created_at DESC, i.id DESC
              LIMIT $size OFFSET $offset",
            $params,
        );
        return [
            'page' => $page,
            'size' => $size,
            'total' => $total,
            'items' => array_map(static fn ($r) => [
                'id' => (int) $r['id'],
                'filename' => $r['original_filename'],
                'fileSize' => (int) $r['file_size'],
                'status' => $r['status'],
                'rows' => (int) $r['data_rows'],
                'errors' => (int) $r['error_count'],
                'warnings' => (int) $r['warning_count'],
                'createdAt' => $r['created_at'],
                'closedAt' => $r['closed_at'],
                'user' => ['username' => $r['username'], 'displayName' => $r['display_name']],
                'conversion' => $r['output_filename'] === null ? null : [
                    'outputFilename' => $r['output_filename'],
                    'rowsOut' => (int) $r['rows_out'],
                    'totalCents' => (int) $r['total_cents'],
                    'zeroPriceRows' => (int) $r['zero_price_rows'],
                    'durationMs' => (int) $r['duration_ms'],
                    'convertedAt' => $r['converted_at'],
                ],
            ], $rows),
        ];
    }

    /** @return array{0: string, 1: list<mixed>} */
    public static function filters(Request $request, array $user, bool $isAdmin, string $alias = 'i'): array
    {
        $where = ['1=1'];
        $params = [];
        if (!$isAdmin) {
            $where[] = "$alias.user_id = ?";
            $params[] = (int) $user['id'];
        } elseif (($uid = $request->queryInt('user', 0, 0, PHP_INT_MAX)) > 0) {
            $where[] = "$alias.user_id = ?";
            $params[] = $uid;
        }
        $status = $request->queryStr('status', 20);
        if (in_array($status, ['rejected', 'blocked', 'ready', 'converted', 'cancelled', 'expired'], true)) {
            $where[] = "$alias.status = ?";
            $params[] = $status;
        }
        if (($from = $request->queryDate('from')) !== null) {
            $where[] = "$alias.created_at >= ?";
            $params[] = "$from 00:00:00";
        }
        if (($to = $request->queryDate('to')) !== null) {
            $where[] = "$alias.created_at <= ?";
            $params[] = "$to 23:59:59";
        }
        $q = $request->queryStr('q', 100);
        if ($q !== '') {
            $where[] = "$alias.original_filename LIKE ?";
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
        }
        return [implode(' AND ', $where), $params];
    }
}
