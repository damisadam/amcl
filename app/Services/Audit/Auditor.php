<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Support\Correlation;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Writes the append-only audit trail. Secrets and sensitive identity values are redacted before storage.
 */
class Auditor
{
    /** Attribute names that are never written to the audit trail in clear text. */
    private const REDACT = ['password', 'pin_hash', 'code_hash', 'credentials', 'remember_token', 'client_secret', 'api_key', 'otp', 'pin', 'secret', 'token'];

    /** Identity fields that are masked rather than dropped. */
    private const MASK = ['cnic', 'subject_cnic', 'signer_cnic'];

    public static function record(
        string $action,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        ?string $reason = null,
        ?int $tenantId = null,
    ): AuditLog {
        $user = auth()->user();
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::create([
            'tenant_id' => $tenantId ?? $subject?->getAttribute('tenant_id') ?? TenantContext::id() ?? $user?->tenant_id,
            'actor_id' => $user?->id,
            'actor_type' => $user?->type?->value ?? 'system',
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'old_values' => self::clean($old) ?: null,
            'new_values' => self::clean($new) ?: null,
            'reason' => $reason,
            'correlation_id' => Correlation::id(),
            'ip' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 500) : null,
        ]);
    }

    /** Audit an Eloquent update using its dirty attributes. Call before save(). */
    public static function changes(string $action, Model $model, ?string $reason = null): ?AuditLog
    {
        $dirty = Arr::except($model->getDirty(), ['updated_at']);
        if (! $dirty) {
            return null;
        }
        $old = array_intersect_key($model->getOriginal(), $dirty);

        return self::record($action, $model, $old, $dirty, $reason);
    }

    public static function clean(array $values): array
    {
        $out = [];
        foreach ($values as $k => $v) {
            $key = strtolower((string) $k);
            if (in_array($key, self::REDACT, true) || str_contains($key, 'secret') || str_contains($key, 'password')) {
                $out[$k] = '[REDACTED]';
            } elseif (in_array($key, self::MASK, true) && is_string($v) && strlen($v) > 6) {
                $out[$k] = substr($v, 0, 5).'*******'.substr($v, -1);
            } elseif ($v instanceof \BackedEnum) {
                $out[$k] = $v->value;
            } elseif ($v instanceof \DateTimeInterface) {
                $out[$k] = $v->format('Y-m-d H:i:s');
            } elseif (is_array($v)) {
                $out[$k] = self::clean($v);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
