<?php

namespace Tests\Feature\Ops\Servicing;

use App\Models\Disbursement;
use App\Models\DocumentType;
use App\Models\LoanAccount;
use App\Models\LoanApplication;
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

abstract class ServicingTestCase extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    /** Drive an application through the real workflow up to ready_for_disbursement. */
    protected function readyApplication(Tenant $tenant): LoanApplication
    {
        return TenantContext::run($tenant, function () use ($tenant) {
            $vendor = $this->vendor($tenant);
            $product = $this->product($tenant);
            $cu = $this->customer($tenant);
            $officer = $this->staff($tenant, 'branch_manager');
            $analyst = $this->staff($tenant, 'credit_analyst');
            $apps = app(ApplicationService::class);
            auth()->setUser($cu);
            $app = $apps->createDraft($cu->customer, $product, ['vendor_item_id' => $vendor->items()->first()->id, 'tenure' => 12, 'purpose' => 'Test'], $cu);
            $apps->submit($app, $cu);
            $apps->startReview($app, $officer);
            app(UnderwritingService::class)->requestBureau($app, $analyst);
            app(UnderwritingService::class)->assess($app->fresh(), $analyst, []);
            $apps->sendForApproval($app->fresh(), $officer);
            foreach (['cro', 'manager_operations', 'compliance_officer', 'risk_officer', 'disbursement_approver'] as $role) {
                app(ApprovalEngine::class)->approve($app->fresh()->approvalRequest, $this->staff($tenant, $role), 'ok');
            }
            $ag = app(AgreementService::class);
            $this->confirmAssetOwnership($app);
            $ag->acknowledgeKfs($app->fresh(), $cu, 'en', '127.0.0.1', 'test');
            $da = $this->staff($tenant, 'disbursement_approver');
            foreach ($app->signatureRequests()->get() as $sr) {
                $sr->signing_method === 'digital' ? $ag->sign($sr, $cu, '127.0.0.1', 'test') : $ag->confirmWetInk($sr, $da, 'received');
            }
            $app->refresh()->forceFill(['cooling_off_ends_at' => now()->subMinute()])->save();
            $ag->completeCoolingOff($app->fresh());
            auth()->logout();

            return $app->fresh();
        });
    }

    protected function disbursedLoan(Tenant $tenant): LoanAccount
    {
        $app = $this->readyApplication($tenant);

        return TenantContext::run($tenant, function () use ($tenant, $app) {
            $d = app(DisbursementService::class)->initiate($app, $this->staff($tenant, 'disbursement_approver'), null, $this->staff($tenant, 'disbursement_approver'));

            return $d->fresh()->loanAccount;
        });
    }
}
