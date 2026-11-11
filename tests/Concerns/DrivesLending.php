<?php

namespace Tests\Concerns;

use App\Models\DocumentType;
use App\Models\LoanAccount;
use App\Models\LoanApplication;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Documents\DocumentStore;
use App\Services\Lending\AgreementService;
use App\Services\Lending\ApplicationService;
use App\Services\Lending\DisbursementService;
use App\Services\Lending\UnderwritingService;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;

/** Drives an application through the full lending workflow using real services. */
trait DrivesLending
{
    use BuildsTenants;

    protected function approvers(Tenant $t): array
    {
        return collect(['cro', 'manager_operations', 'compliance_officer', 'risk_officer', 'disbursement_approver'])
            ->mapWithKeys(fn ($r) => [$r => $this->staff($t, $r)])->all();
    }

    protected function submittedApplication(Tenant $t, User $cu, Product $p, Vendor $v): LoanApplication
    {
        return TenantContext::run($t, function () use ($cu, $p, $v) {
            auth()->setUser($cu);
            $app = app(ApplicationService::class)->createDraft($cu->customer, $p, ['vendor_item_id' => $v->items()->first()->id, 'tenure' => 12], $cu);

            return app(ApplicationService::class)->submit($app, $cu);
        });
    }

    protected function reviewed(Tenant $t, LoanApplication $app): LoanApplication
    {
        return TenantContext::run($t, function () use ($t, $app) {
            $bm = $this->staff($t, 'branch_manager');
            $ca = $this->staff($t, 'credit_analyst');
            $vo = $this->staff($t, 'visit_officer');
            $apps = app(ApplicationService::class);
            $uw = app(UnderwritingService::class);
            $apps->startReview($app, $bm);
            $uw->requestBureau($app->fresh(), $ca);
            $visit = $uw->scheduleVisit($app->fresh(), $bm, 'residence', $vo, today()->toDateString());
            $uw->checkIn($visit, $vo, 31.5, 74.3, 25.0);
            app(DocumentStore::class)->store(UploadedFile::fake()->image('v.jpg'), $visit, DocumentType::where('code', 'VISIT_PHOTO')->first(), $vo);
            $uw->submitVisit($visit->fresh(), $vo, ['recommendation' => 'positive', 'address_match' => true]);
            $uw->assess($app->fresh(), $ca, []);
            $apps->sendForApproval($app->fresh(), $bm);

            return $app->fresh();
        });
    }

    protected function approveAll(Tenant $t, LoanApplication $app, array $approvers): LoanApplication
    {
        return TenantContext::run($t, function () use ($app, $approvers) {
            foreach ($approvers as $u) {
                app(ApprovalEngine::class)->approve($app->fresh()->approvalRequest, $u->fresh('roles'), 'Approved in test');
            }

            return $app->fresh();
        });
    }

    protected function signedAndReady(Tenant $t, LoanApplication $app, User $cu, User $disbApprover): LoanApplication
    {
        return TenantContext::run($t, function () use ($app, $cu, $disbApprover) {
            $ag = app(AgreementService::class);
            $this->confirmAssetOwnership($app);
            $ag->acknowledgeKfs($app->fresh(), $cu, 'en', '127.0.0.1', 'test');
            foreach ($app->signatureRequests()->get() as $sr) {
                $sr->signing_method === 'digital' ? $ag->sign($sr, $cu, '127.0.0.1', 'test') : $ag->confirmWetInk($sr, $disbApprover, 'received');
            }
            $app->refresh()->forceFill(['cooling_off_ends_at' => now()->subMinute()])->save();
            $ag->completeCoolingOff($app->fresh());

            return $app->fresh();
        });
    }

    protected function disbursedLoan(Tenant $t, ?User $cu = null, string $scenario = 'success'): LoanAccount
    {
        $cu ??= $this->customer($t);
        $p = $this->product($t);
        $v = $this->vendor($t);
        $approvers = $this->approvers($t);
        $app = $this->submittedApplication($t, $cu, $p, $v);
        $app = $this->reviewed($t, $app);
        $app = $this->approveAll($t, $app, $approvers);
        $app = $this->signedAndReady($t, $app, $cu, $approvers['disbursement_approver']);

        $releaser = $this->staff($t, 'disbursement_approver'); // maker-checker: a second user releases the payout

        return TenantContext::run($t, function () use ($app, $approvers, $scenario, $releaser) {
            $d = app(DisbursementService::class)->initiate($app, $approvers['disbursement_approver'], $scenario, $releaser);

            return $d->fresh()->loanAccount ?? throw new \RuntimeException('Disbursement did not create a loan: '.$d->fresh()->status->value);
        });
    }
}
