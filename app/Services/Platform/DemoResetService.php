<?php

namespace App\Services\Platform;

use App\Exceptions\DomainException;
use App\Jobs\ResetDemoEnvironment;
use App\Models\DemoResetRequest;
use App\Models\User;
use App\Services\Audit\Auditor;

/**
 * Demo reset with two-person control (S4): one platform admin requests, a DIFFERENT platform admin
 * approves, and only then is the reset job queued. Always refused in production or without demo mode.
 */
class DemoResetService
{
    public const PHRASE = 'RESET DEMO';

    public static function allowed(): bool
    {
        return ! app()->isProduction() && (bool) config('integrations.demo_mode');
    }

    public function request(User $by, string $phrase, string $reason): DemoResetRequest
    {
        $this->guard($by);
        if (trim($phrase) !== self::PHRASE) {
            throw new DomainException('Type '.self::PHRASE.' exactly to confirm.');
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw new DomainException('Give a reason (at least 10 characters).');
        }
        if (DemoResetRequest::where('status', 'pending')->exists()) {
            throw new DomainException('A reset request is already waiting for approval.');
        }
        $req = DemoResetRequest::create(['requested_by' => $by->id, 'reason' => trim($reason), 'status' => 'pending']);
        Auditor::record('platform.demo_reset_requested', $req, [], ['reason' => $req->reason]);

        return $req;
    }

    public function approve(DemoResetRequest $req, User $by): void
    {
        $this->guard($by);
        if ($req->status !== 'pending') {
            throw new DomainException('This request was already decided.');
        }
        if ((int) $req->requested_by === (int) $by->id) {
            throw new DomainException('A second platform administrator must approve a demo reset.');
        }
        $req->update(['status' => 'approved', 'decided_by' => $by->id, 'decided_at' => now()]);
        Auditor::record('platform.demo_reset_approved', $req, [], ['requested_by' => $req->requested_by]);
        ResetDemoEnvironment::dispatch($req->requested_by);
    }

    public function reject(DemoResetRequest $req, User $by, bool $cancel = false): void
    {
        if ($req->status !== 'pending') {
            throw new DomainException('This request was already decided.');
        }
        if ($cancel && (int) $req->requested_by !== (int) $by->id) {
            throw new DomainException('Only the requester can cancel.');
        }
        $req->update(['status' => $cancel ? 'cancelled' : 'rejected', 'decided_by' => $by->id, 'decided_at' => now()]);
        Auditor::record('platform.demo_reset_'.($cancel ? 'cancelled' : 'rejected'), $req);
    }

    private function guard(User $by): void
    {
        if (! self::allowed()) {
            Auditor::record('platform.demo_reset_refused', null, [], ['env' => app()->environment()]);
            throw new DomainException('Demo reset is disabled in production or when demo mode is off.');
        }
        if (! $by->isPlatform() || ! $by->hasPermission('platform.demo.reset')) {
            throw new DomainException('Not authorized.');
        }
    }
}
