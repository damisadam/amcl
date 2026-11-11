<?php

namespace Tests\Feature\Customer;

use App\Models\LoanApplication;
use App\Models\Tenant;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Lending\ApplicationService;
use App\Services\Lending\UnderwritingService;
use App\Support\TenantContext;

/** Drives the staff side (review → bureau → assessment → 5-stage approval) through services. */
trait ProcessesApplications
{
    protected function approveThroughOps(Tenant $tenant, LoanApplication $app): LoanApplication
    {
        return TenantContext::run($tenant, function () use ($tenant, $app) {
            $bm = $this->staff($tenant, 'branch_manager');
            $ca = $this->staff($tenant, 'credit_analyst');
            $apps = app(ApplicationService::class);
            $uw = app(UnderwritingService::class);
            $apps->startReview($app->fresh(), $bm);
            $uw->requestBureau($app->fresh(), $ca);
            $uw->assess($app->fresh(), $ca, []);
            $apps->sendForApproval($app->fresh(), $bm);
            foreach (['cro', 'manager_operations', 'compliance_officer', 'risk_officer', 'disbursement_approver'] as $role) {
                app(ApprovalEngine::class)->approve($app->fresh()->approvalRequest, $this->staff($tenant, $role), 'Approved in test');
            }

            return $app->fresh();
        });
    }

    /** @return array{0:\App\Models\User,1:\App\Models\LoanAccount} */
    protected function disbursedLoan($tenant): array
    {
        $vendor = $this->vendor($tenant);
        $product = $this->product($tenant, ['cooling_off_hours' => 0]);
        $user = $this->customer($tenant);
        TenantContext::set($tenant);
        $app = app(\App\Services\Lending\ApplicationService::class)->createDraft($user->customer, $product, ['vendor_item_id' => $vendor->items()->first()->id, 'tenure' => 12, 'purpose' => 'test'], $user);
        app(\App\Services\Lending\ApplicationService::class)->submit($app, $user);
        $app = $this->approveThroughOps($tenant, $app);
        $ag = app(\App\Services\Lending\AgreementService::class);
        $this->confirmAssetOwnership($app);
        $ag->acknowledgeKfs($app, $user, 'en');
        $da = $this->staff($tenant, 'disbursement_approver');
        foreach ($app->signatureRequests()->get() as $sr) {
            $sr->signing_method === 'digital' ? $ag->sign($sr, $user) : $ag->confirmWetInk($sr, $da, 'ok');
        }
        $app->refresh()->forceFill(['cooling_off_ends_at' => now()->subMinute()])->save();
        $loan = app(\App\Services\Lending\DisbursementService::class)->initiate($app->fresh(), $da, null, $this->staff($tenant, 'disbursement_approver'))->fresh()->loanAccount;
        TenantContext::set($tenant);

        return [$user, $loan];
    }
}
