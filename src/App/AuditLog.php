<?php

declare(strict_types=1);

namespace TransformerX\App;

use TransformerX\App\Http\Request;

final class AuditLog
{
    public static function record(Request $request, ?int $userId, string $action, ?string $targetType = null, ?int $targetId = null, array $details = []): void
    {
        try {
            Db::insert('audit_log', [
                'user_id' => $userId,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'details_json' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip' => $request->ip,
                'user_agent' => $request->userAgent,
            ]);
        } catch (\Throwable $e) {
            Log::error('audit write failed', $e);
        }
    }
}
