<?php

namespace Tests\Feature\Finance;

use App\Enums\ApprovalType;
use App\Models\DocumentType;
use App\Models\LoanAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Documents\DocumentStore;
use App\Services\Lending\AgreementService;
use App\Services\Lending\ApplicationService;
use App\Services\Lending\DisbursementService;
use App\Services\Lending\UnderwritingService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

abstract class FinanceTestCase extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    /** Drive one financing through the real services to disbursement; returns the loan account. */
    protected function disbursedLoan(Tenant $tenant): LoanAccount
    {
        return TenantContext::run($tenant, function () use ($tenant) {
            $customer = $this->customer($tenant);
            $vendor = $this->vendor($tenant);
            $product = $this->product($tenant);
            $bm = $this->staff($tenant, 'branch_manager');
            $ca = $this->staff($tenant, 'credit_analyst');
            $apps = app(ApplicationService::class);
            $app = $apps->createDraft($customer->customer, $product, ['vendor_item_id' => $vendor->items()->first()->id, 'tenure' => 12], $customer);
            $apps->submit($app, $customer);
            $apps->startReview($app, $bm);
            $uw = app(UnderwritingService::class);
            $uw->requestBureau($app, $ca);
            $uw->assess($app->fresh(), $ca, []);
            $apps->sendForApproval($app->fresh(), $bm);
            foreach (['cro', 'manager_operations', 'compliance_officer', 'risk_officer', 'disbursement_approver'] as $role) {
                app(ApprovalEngine::class)->approve($app->fresh()->approvalRequest, $this->staff($tenant, $role), 'ok');
            }
            $ag = app(AgreementService::class);
            $this->confirmAssetOwnership($app);
            $ag->acknowledgeKfs($app->fresh(), $customer, 'en', '127.0.0.1', 'test');
            $da = $this->staff($tenant, 'disbursement_approver');
            foreach ($app->signatureRequests()->get() as $sr) {
                $sr->signing_method === 'digital' ? $ag->sign($sr, $customer, '127.0.0.1', 'test') : $ag->confirmWetInk($sr, $da, 'received');
            }
            $app->refresh()->forceFill(['cooling_off_ends_at' => now()->subMinute()])->save();
            $d = app(DisbursementService::class)->initiate($app->fresh(), $da, null, $this->staff($tenant, 'disbursement_approver'));

            return $d->fresh()->loanAccount;
        });
    }

    protected function financeOfficer(Tenant $tenant): User
    {
        return $this->staff($tenant, 'finance_officer');
    }

    protected function accountant(Tenant $tenant): User
    {
        return $this->staff($tenant, 'accountant');
    }
}
