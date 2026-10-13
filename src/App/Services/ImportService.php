<?php

declare(strict_types=1);

namespace TransformerX\App\Services;

use TransformerX\App\AuditLog;
use TransformerX\App\Auth\Auth;
use TransformerX\App\Config;
use TransformerX\App\Db;
use TransformerX\App\Http\HttpError;
use TransformerX\App\Http\Request;
use TransformerX\App\Http\Response;
use TransformerX\App\Log;
use TransformerX\App\Settings;
use TransformerX\Engine\Analyzer;
use TransformerX\Engine\Converter;
use TransformerX\Engine\Exporter;
use TransformerX\Engine\InputReader;
use TransformerX\Engine\Issue;
use TransformerX\Engine\OutputWriter;
use TransformerX\Engine\Schema;

/**
 * Upload -> preview -> convert. The uploaded file is deleted as soon as it has been read, the parsed
 * rows live in import_rows only while the preview is open, and the output file is streamed and deleted.
 */
final class ImportService
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
    private const ACCEPTED_MIME = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/octet-stream',
    ];

    public function upload(Request $request, array $user): array
    {
        $file = $request->files['file'] ?? null;
        if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
            throw HttpError::badRequest('Choose one .xlsx file to upload.');
        }
        $maxMb = Settings::int('max_upload_mb');
        match ((int) $file['error']) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => throw HttpError::badRequest("The file is larger than $maxMb MB."),
            UPLOAD_ERR_NO_FILE => throw HttpError::badRequest('Choose a file to upload.'),
            UPLOAD_ERR_PARTIAL => throw HttpError::badRequest('The upload was interrupted. Try again.'),
            default => throw new HttpError(500, 'upload_failed', 'The server could not receive the file. Try again.'),
        };
        if (!is_uploaded_file($file['tmp_name'])) {
            throw HttpError::badRequest('Choose a file to upload.');
        }
        $size = (int) $file['size'];
        if ($size <= 0 || $size > $maxMb * 1024 * 1024) {
            throw HttpError::badRequest("The file must be between 1 byte and $maxMb MB.");
        }
        $name = self::cleanFilename((string) $file['name']);
        if (!preg_match('/\.xlsx$/i', $name)) {
            throw HttpError::badRequest('Only Excel .xlsx files are accepted. In Excel use File > Save As > "Excel Workbook (*.xlsx)".');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        if (!in_array($mime, self::ACCEPTED_MIME, true)) {
            throw HttpError::badRequest('This file is not an Excel workbook.');
        }

        $work = Config::storage('tmp') . '/up_' . bin2hex(random_bytes(16)) . '.xlsx';
        if (!move_uploaded_file($file['tmp_name'], $work)) {
            throw new HttpError(500, 'upload_failed', 'The server could not store the upload. Try again.');
        }
        try {
            $sha = hash_file('sha256', $work);
            $t0 = hrtime(true);
            $read = (new InputReader(Settings::int('max_rows')))->read($work);
        } finally {
            @unlink($work);   // the upload is never kept
        }

        $analysis = (new Analyzer())->analyse($read['rows'], $read['issues'], $read['blankRows']);
        $ms = (int) ((hrtime(true) - $t0) / 1e6);
        $summary = $analysis['summary'];
        $fileErrors = InputReader::hasErrors($analysis['fileIssues']);
        $status = $fileErrors ? 'rejected' : ($summary['canConvert'] ? 'ready' : 'blocked');

        $id = Db::transaction(function () use ($user, $name, $size, $sha, $status, $summary, $analysis, $ms, $fileErrors): int {
            $id = Db::insert('imports', [
                'user_id' => $user['id'],
                'original_filename' => $name,
                'file_size' => $size,
                'file_sha256' => $sha,
                'status' => $status,
                'data_rows' => $summary['rows'],
                'blank_rows' => $summary['blankRowsSkipped'],
                'error_count' => $summary['errorCount'],
                'warning_count' => $summary['warningCount'],
                'summary_json' => json_encode($summary, self::JSON_FLAGS),
                'file_issues_json' => json_encode($analysis['fileIssues'], self::JSON_FLAGS),
                'engine_version' => Schema::ENGINE_VERSION,
                'analysis_ms' => $ms,
                'closed_at' => $fileErrors ? date('Y-m-d H:i:s') : null,
            ]);
            foreach (['error' => $summary['errorsByCode'], 'warning' => $summary['warningsByCode']] as $severity => $codes) {
                foreach ($codes as $code => $count) {
                    Db::insert('import_issue_counts', ['import_id' => $id, 'severity' => $severity, 'code' => $code, 'count' => $count]);
                }
            }
            if (!$fileErrors) {
                self::stageRows($id, $analysis['rows']);
            }
            return $id;
        });

        AuditLog::record($request, (int) $user['id'], 'import_uploaded', 'import', $id, [
            'file' => $name, 'rows' => $summary['rows'], 'status' => $status, 'errors' => $summary['errorCount'], 'warnings' => $summary['warningCount'],
        ]);
        return $this->present($this->find($id));
    }

    /** Import record the current user may see (own, or any for admins). */
    public function find(int $id): array
    {
        $row = Db::one('SELECT i.*, u.username, u.display_name FROM imports i JOIN users u ON u.id = i.user_id WHERE i.id = ?', [$id]);
        if ($row === null || (!Auth::isAdmin() && (int) $row['user_id'] !== (int) Auth::user()['id'])) {
            throw HttpError::notFound('This import does not exist or belongs to someone else.');
        }
        return $row;
    }

    public function present(array $row): array
    {
        $conversion = Db::one('SELECT * FROM conversions WHERE import_id = ?', [$row['id']]);
        $isOwner = (int) $row['user_id'] === (int) Auth::user()['id'];
        return [
            'id' => (int) $row['id'],
            'filename' => $row['original_filename'],
            'fileSize' => (int) $row['file_size'],
            'status' => $row['status'],
            'createdAt' => $row['created_at'],
            'closedAt' => $row['closed_at'],
            'user' => ['id' => (int) $row['user_id'], 'username' => $row['username'], 'displayName' => $row['display_name']],
            'summary' => json_decode($row['summary_json'], true),
            'fileIssues' => json_decode($row['file_issues_json'], true),
            'analysisMs' => (int) $row['analysis_ms'],
            'engineVersion' => $row['engine_version'],
            'previewOpen' => in_array($row['status'], ['ready', 'blocked'], true),
            'canConvert' => $isOwner && $row['status'] === 'ready',
            'canCancel' => $isOwner && in_array($row['status'], ['ready', 'blocked'], true),
            'previewExpiresAt' => in_array($row['status'], ['ready', 'blocked'], true)
                ? date('Y-m-d H:i:s', strtotime($row['created_at']) + Settings::int('preview_expiry_hours') * 3600) : null,
            'conversion' => $conversion === null ? null : self::presentConversion($conversion),
        ];
    }

    public static function presentConversion(array $c): array
    {
        return [
            'id' => (int) $c['id'],
            'outputFilename' => $c['output_filename'],
            'rowsOut' => (int) $c['rows_out'],
            'totalCents' => (int) $c['total_cents'],
            'zeroPriceRows' => (int) $c['zero_price_rows'],
            'blankPriceRows' => (int) $c['blank_price_rows'],
            'negativePriceRows' => (int) $c['negative_price_rows'],
            'attributesDroppedRows' => (int) $c['attributes_dropped_rows'],
            'attributeCount' => (int) $c['attribute_count'],
            'outputSize' => (int) $c['output_size'],
            'outputSha256' => $c['output_sha256'],
            'durationMs' => (int) $c['duration_ms'],
            'engineVersion' => $c['engine_version'],
            'createdAt' => $c['created_at'],
            'breakdown' => array_map(static fn ($b) => [
                'dimension' => $b['dimension'], 'name' => $b['name'], 'rows' => (int) $b['row_count'],
                'totalCents' => (int) $b['total_cents'], 'zeroPriceRows' => (int) $b['zero_price_rows'],
            ], Db::all('SELECT * FROM conversion_breakdown WHERE conversion_id = ? ORDER BY dimension, row_count DESC', [$c['id']])),
        ];
    }

    /** One page of the preview grid. view=input shows the sheet as read; view=output the converted row. */
    public function rows(int $id, Request $request): array
    {
        $import = $this->find($id);
        if (!in_array($import['status'], ['ready', 'blocked'], true)) {
            throw HttpError::conflict('This preview has closed. Upload the file again to see its rows.');
        }
        $page = $request->queryInt('page', 1, 1, 100000);
        $size = $request->queryInt('size', 50, 10, 200);
        $status = $request->queryStr('status', 10);
        $q = mb_strtolower($request->queryStr('q', 100));
        $view = $request->queryStr('view', 10) === 'output' ? 'output' : 'input';

        $where = 'import_id = ?';
        $params = [$id];
        if (in_array($status, ['ok', 'warning', 'error'], true)) {
            $where .= ' AND status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $where .= ' AND search_text LIKE ?';
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
        }
        $total = (int) Db::value("SELECT COUNT(*) FROM import_rows WHERE $where", $params);
        $offset = ($page - 1) * $size;
        $rows = Db::all("SELECT row_no, status, data_json FROM import_rows WHERE $where ORDER BY seq LIMIT $size OFFSET $offset", $params);

        $converter = new Converter();
        $out = [];
        foreach ($rows as $r) {
            $data = json_decode($r['data_json'], true);
            $item = ['row' => (int) $r['row_no'], 'status' => $r['status'], 'issues' => $data['issues']];
            if ($view === 'output') {
                $item['cells'] = $r['status'] === 'error' ? null : $converter->convert($data['v'])['cells'];
            } else {
                $item['cells'] = array_map(static fn ($c) => $data['v'][$c] ?? null, Schema::INPUT_COLUMNS);
            }
            $out[] = $item;
        }
        return [
            'view' => $view,
            'columns' => $view === 'output' ? Schema::outputColumns() : Schema::INPUT_COLUMNS,
            'numericColumns' => $view === 'output' ? ['Length', 'Width', 'Height', 'Stock', 'Is featured?', 'Allow customer reviews?'] : Schema::NUMERIC_COLUMNS,
            'page' => $page,
            'size' => $size,
            'total' => $total,
            'rows' => $out,
        ];
    }

    /** Everything about one row: what was read, every calculation step, and the resulting output cells. */
    public function rowDetail(int $id, int $rowNo): array
    {
        $import = $this->find($id);
        if (!in_array($import['status'], ['ready', 'blocked'], true)) {
            throw HttpError::conflict('This preview has closed.');
        }
        $r = Db::one('SELECT row_no, status, data_json FROM import_rows WHERE import_id = ? AND row_no = ?', [$id, $rowNo]);
        if ($r === null) {
            throw HttpError::notFound('Row not found.');
        }
        $data = json_decode($r['data_json'], true);
        $detail = ['row' => (int) $r['row_no'], 'status' => $r['status'], 'issues' => $data['issues'], 'input' => $data['v'], 'conversion' => null];
        if ($r['status'] !== 'error') {
            $c = (new Converter())->convert($data['v']);
            $detail['conversion'] = [
                'price' => $c['price'],
                'size' => $c['size'],
                'categories' => $c['categories'],
                'attributes' => $c['attributes'],
                'droppedAttributes' => $c['droppedAttributes'],
                'output' => array_combine(Schema::outputColumns(), $c['cells']),
            ];
        }
        return $detail;
    }

    /** Converts, records the statistics, discards the staged rows and streams the file (then deletes it). */
    public function convert(int $id, Request $request, array $user): Response
    {
        $import = $this->find($id);
        if ((int) $import['user_id'] !== (int) $user['id']) {
            throw HttpError::forbidden('Only the person who uploaded this file can convert it.');
        }
        // Claim the import atomically so a double click cannot convert it twice.
        $claimed = Db::exec("UPDATE imports SET status = 'converted', closed_at = NOW() WHERE id = ? AND status = 'ready'", [$id]);
        if ($claimed !== 1) {
            throw HttpError::conflict($import['status'] === 'converted'
                ? 'This file has already been converted. Upload it again to download another copy.'
                : 'This preview cannot be converted (it has errors, was cancelled or has expired).');
        }

        $path = Config::storage('tmp') . '/out_' . bin2hex(random_bytes(16)) . '.xlsx';
        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                @unlink($path);
            }
        });
        try {
            $t0 = hrtime(true);
            $result = (new Exporter())->export(self::stagedRows($id), $path);
            $ms = (int) ((hrtime(true) - $t0) / 1e6);
            $filename = OutputWriter::fileName(new \DateTimeImmutable());
            $stats = $result['stats'];

            $conversionId = Db::transaction(function () use ($id, $user, $filename, $result, $stats, $path, $ms): int {
                $cid = Db::insert('conversions', [
                    'import_id' => $id,
                    'user_id' => $user['id'],
                    'output_filename' => $filename,
                    'rows_out' => $result['rows'],
                    'total_cents' => $stats['totalCents'],
                    'zero_price_rows' => $stats['zeroPrice'],
                    'blank_price_rows' => $stats['blankPrice'],
                    'negative_price_rows' => $stats['negativePrice'],
                    'attributes_dropped_rows' => $stats['attributesDropped'],
                    'attribute_count' => $stats['attributeCount'],
                    'output_size' => filesize($path),
                    'output_sha256' => hash_file('sha256', $path),
                    'duration_ms' => $ms,
                    'engine_version' => Schema::ENGINE_VERSION,
                ]);
                foreach (['category' => $stats['byCategory'], 'product_type' => $stats['byProductType']] as $dimension => $groups) {
                    foreach ($groups as $name => $g) {
                        Db::insert('conversion_breakdown', [
                            'conversion_id' => $cid, 'dimension' => $dimension, 'name' => mb_substr((string) $name, 0, 255),
                            'row_count' => $g['rows'], 'total_cents' => $g['cents'], 'zero_price_rows' => $g['zeroPrice'],
                        ]);
                    }
                }
                Db::exec('DELETE FROM import_rows WHERE import_id = ?', [$id]);
                return $cid;
            });
        } catch (\Throwable $e) {
            @unlink($path);
            Db::exec("UPDATE imports SET status = 'ready', closed_at = NULL WHERE id = ? AND status = 'converted' AND NOT EXISTS (SELECT 1 FROM conversions WHERE import_id = ?)", [$id, $id]);
            $ref = Log::error('conversion failed', $e, ['import' => $id]);
            throw new HttpError(500, 'conversion_failed', "The conversion failed and nothing was saved. Try again; if it keeps failing, tell an administrator (reference $ref).");
        }

        AuditLog::record($request, (int) $user['id'], 'import_converted', 'import', $id, ['conversion' => $conversionId, 'rows' => $result['rows'], 'file' => $filename]);
        return Response::download($path, $filename, true);
    }

    public function cancel(int $id, Request $request, array $user): array
    {
        $import = $this->find($id);
        if ((int) $import['user_id'] !== (int) $user['id']) {
            throw HttpError::forbidden('Only the person who uploaded this file can cancel it.');
        }
        $n = Db::exec("UPDATE imports SET status = 'cancelled', closed_at = NOW() WHERE id = ? AND status IN ('ready','blocked')", [$id]);
        if ($n === 1) {
            Db::exec('DELETE FROM import_rows WHERE import_id = ?', [$id]);
            AuditLog::record($request, (int) $user['id'], 'import_cancelled', 'import', $id);
        }
        return $this->present($this->find($id));
    }

    // ------------------------------------------------------------------ internals

    private static function stageRows(int $importId, array $rows): void
    {
        foreach (array_chunk($rows, 250) as $chunkIndex => $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $i => $r) {
                $v = $r['v'];
                $search = mb_strtolower(implode(' ', array_filter([
                    $v['Internal Reference'], $v['Name'], $v['Product Category'], $v['Product Type'], $v['Species'],
                ], 'is_string')));
                $placeholders[] = '(?, ?, ?, ?, ?, ?)';
                array_push(
                    $params,
                    $importId,
                    $chunkIndex * 250 + $i,
                    $r['row'],
                    $r['status'],
                    mb_substr($search, 0, 600),
                    json_encode(['v' => $v, 'issues' => $r['issues']], self::JSON_FLAGS),
                );
            }
            Db::exec('INSERT INTO import_rows (import_id, seq, row_no, status, search_text, data_json) VALUES ' . implode(', ', $placeholders), $params);
        }
    }

    /** @return \Generator<array{row: int, v: array}> */
    private static function stagedRows(int $importId): \Generator
    {
        $last = -1;
        do {
            $batch = Db::all('SELECT seq, row_no, data_json FROM import_rows WHERE import_id = ? AND seq > ? ORDER BY seq LIMIT 1000', [$importId, $last]);
            foreach ($batch as $r) {
                $last = (int) $r['seq'];
                yield ['row' => (int) $r['row_no'], 'v' => json_decode($r['data_json'], true)['v']];
            }
        } while (count($batch) === 1000);
    }

    private static function cleanFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);
        return $name === '' ? 'upload.xlsx' : mb_substr($name, 0, 255);
    }
}
