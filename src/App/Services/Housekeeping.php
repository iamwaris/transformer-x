<?php

declare(strict_types=1);

namespace TransformerX\App\Services;

use TransformerX\App\Config;
use TransformerX\App\Db;
use TransformerX\App\Log;
use TransformerX\App\Settings;

/**
 * Runs at most every 10 minutes, piggy-backing on normal requests:
 * expires abandoned previews (deleting their staged rows), prunes old sign-in attempts,
 * and removes any temp file a crashed request left behind.
 */
final class Housekeeping
{
    private const INTERVAL = 600;

    public static function maybeRun(): void
    {
        $stamp = Config::storage('tmp') . '/.housekeeping';
        if (is_file($stamp) && time() - filemtime($stamp) < self::INTERVAL) {
            return;
        }
        @touch($stamp);
        try {
            self::run();
        } catch (\Throwable $e) {
            Log::error('housekeeping failed', $e);
        }
    }

    public static function run(): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - Settings::int('preview_expiry_hours') * 3600);
        $ids = array_map('intval', array_column(
            Db::all("SELECT id FROM imports WHERE status IN ('ready','blocked') AND created_at < ?", [$cutoff]),
            'id',
        ));
        foreach ($ids as $id) {
            Db::transaction(static function () use ($id): void {
                Db::exec("UPDATE imports SET status = 'expired', closed_at = NOW() WHERE id = ? AND status IN ('ready','blocked')", [$id]);
                Db::exec('DELETE FROM import_rows WHERE import_id = ?', [$id]);
            });
        }
        // Rows can only exist for open previews.
        Db::exec("DELETE r FROM import_rows r JOIN imports i ON i.id = r.import_id WHERE i.status NOT IN ('ready','blocked')");
        Db::exec('DELETE FROM login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 30 * 86400)]);

        foreach (glob(Config::storage('tmp') . '/{up_,out_,tpl_}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file) && time() - filemtime($file) > 3600) {
                @unlink($file);
            }
        }
    }
}
