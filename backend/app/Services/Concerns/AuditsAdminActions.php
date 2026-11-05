<?php

namespace App\Services\Concerns;

use App\Exceptions\AdminActionException;
use App\Models\AuditLog;
use App\Models\User;

trait AuditsAdminActions
{
    private function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw AdminActionException::because('reason_required');
        }

        return $reason;
    }

    private function audit(User $admin, string $action, string $subjectType, int $subjectId, string $reason, array $meta = []): void
    {
        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'meta' => $meta + ['reason' => $reason],
        ]);
    }
}
