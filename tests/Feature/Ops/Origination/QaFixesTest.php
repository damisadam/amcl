<?php

namespace Tests\Feature\Ops\Origination;

use App\Enums\AmlStatus;
use App\Enums\ApplicationStatus;
use App\Enums\KycStatus;
use App\Exceptions\DomainException;
use App\Livewire\Ops\Applications\Show as AppShow;
use App\Livewire\Ops\Approvals\Index as Approvals;
use App\Livewire\Ops\Customers\Index as CustomerIndex;
use App\Livewire\Ops\Customers\Show as CustomerShow;
use App\Livewire\Ops\Dashboard;
use App\Livewire\Ops\FieldVisits\Show as VisitShow;
use App\Livewire\Ops\Kyc\Index as KycIndex;
use App\Livewire\Public\GuarantorSign;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DocumentType;
use App\Models\FieldVisit;
use App\Models\Guarantor;
use App\Models\LoanApplication;
use App\Models\SignatureRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Documents\DocumentStore;
use App\Services\Kyc\OnboardingService;
use App\Services\Lending\AgreementService;
use App\Services\Lending\ApplicationService;
use App\Services\Lending\UnderwritingService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Regression tests for QA review findings C2, C5, H4, H5, H6, S8, M2/M3, M11–M14, M18. */
class QaFixesTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function as(User $u): static
    {
        TenantContext::set(Tenant::find($u->tenant_id));
        $this->actingAs($u);

        return $this;
    }

    /** Application under review with one guarantor (product requires one). */
    private function underReview(array $guarantor = []): array
    {
        $t = $this->makeTenant('alpha');
        $product = $this->product($t, ['guarantors_required' => 1]);
        $vendor = $this->vendor($t);
        $cu = $this->customer($t);
        $apps = app(ApplicationService::class);
        TenantContext::set($t);
        auth()->setUser($cu);
        $app = $apps->createDraft($cu->customer, $product, ['vendor_item_id' => $vendor->items()->first()->id, 'tenure' => 12], $cu);
        $g = $apps->addGuarantor($app, array_merge(['name' => 'Bilal Guarantor', 'cnic' => '99555-1234567-2', 'mobile' => '03111234567', 'relation' => 'Cousin',
            'monthly_income' => '90000', 'consent' => true], $guarantor), $cu);
        $apps->submit($app, $cu);
        $bm = $this->staff($t, 'branch_manager');
        $apps->startReview($app->fresh(), $bm);

        return [$t, $app->fresh(), $g->fresh(), $bm, $cu];
    }

    private function completeVisit(Tenant $t, LoanApplication $app, User $bm): FieldVisit
    {
        $vo = $this->staff($t, 'visit_officer');
        $uw = app(UnderwritingService::class);
        $v = $uw->scheduleVisit($app, $bm, 'residence', $vo, today()->toDateString());
        $uw->checkIn($v, $vo, 31.5, 74.3, 15.0);
        app(DocumentStore::class)->store(UploadedFile::fake()->image('p.jpg'), $v, DocumentType::where('code', 'VISIT_PHOTO')->first(), $vo);

        return $uw->submitVisit($v->fresh(), $vo, ['recommendation' => 'positive', 'address_match' => true, 'checklist' => ['met_applicant' => true, 'cnic_sighted' => false]]);
    }

    public function test_c2_guarantor_must_be_verified_before_approval_and_assessment_reports_real_status(): void
    {
        [$t, $app, $g, $bm] = $this->underReview(['monthly_income' => '0']);
        $this->assertNotNull($g->aml_checked_at, 'guarantor AML screening runs automatically on add');
        $this->assertSame('clear', $g->aml_status);
        $ca = $this->staff($t, 'credit_analyst');
        $uw = app(UnderwritingService::class);
        $uw->requestBureau($app, $ca);
        $this->completeVisit($t, $app, $bm);
        $a = $uw->assess($app->fresh(), $ca, ['monthly_income' => '90000']);
        $this->assertSame('pending', $a->guarantor_result, 'never "verified" just because a guarantor exists');

        try {
            app(ApplicationService::class)->sendForApproval($app->fresh(), $bm);
            $this->fail('approval must be blocked while the guarantor is unverified');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Bilal Guarantor is not verified', $e->getMessage());
            $this->assertStringContainsString('bureau', $e->getMessage());
            $this->assertStringContainsString('income', $e->getMessage());
        }

        $g->update(['monthly_income' => '90000']);
        $uw->requestBureau($app->fresh(), $ca, 'guarantor', $g->fresh());
        $this->assertSame('verified', $g->fresh()->verification_status);
        $uw->assess($app->fresh(), $ca, ['monthly_income' => '90000']);
        $this->assertSame('verified', $app->fresh()->assessments()->first()->guarantor_result);
        app(ApplicationService::class)->sendForApproval($app->fresh(), $bm);
        $this->assertSame(ApplicationStatus::PendingApproval, $app->fresh()->status);

        // S8: approvers see guarantor + compliance readiness in the approval modal
        $cro = $this->staff($t, 'cro');
        $this->as($cro);
        Livewire::test(Approvals::class)->call('select', $app->fresh()->approvalRequest->id)
            ->assertSee('Readiness checklist')->assertSee('Bilal Guarantor')->assertSee('Verified (bureau + AML)')->assertSee('Sanctions / AML screening');
    }

    public function test_c2_aml_hit_on_guarantor_keeps_it_pending(): void
    {
        [$t, $app, $g] = $this->underReview(['name' => 'PEP Relative Guarantor', 'cnic' => '99555-7654321-9']);
        $this->assertSame('potential_match', $g->aml_status);
        $readiness = app(UnderwritingService::class)->guarantorReadiness($g);
        $this->assertSame('pending', $readiness['status']);
        $this->assertStringContainsString('compliance review', implode(' ', $readiness['reasons']));
    }

    public function test_c5_check_in_requires_accurate_gps_on_the_scheduled_day_and_submit_requires_check_in(): void
    {
        [$t, $app, , $bm] = $this->underReview();
        $vo = $this->staff($t, 'visit_officer');
        $uw = app(UnderwritingService::class);
        $v = $uw->scheduleVisit($app, $bm, 'residence', $vo, today()->addDay()->toDateString());
        $this->as($vo);

        Livewire::test(VisitShow::class, ['visit' => $v])->call('checkIn')->assertHasErrors('action');
        Livewire::test(VisitShow::class, ['visit' => $v])->set('lat', 31.5)->set('lng', 74.3)->set('accuracy', 450.0)->call('checkIn')->assertHasErrors('action');
        $c = Livewire::test(VisitShow::class, ['visit' => $v])->set('lat', 31.5)->set('lng', 74.3)->set('accuracy', 20.0)->call('checkIn');
        $c->assertHasErrors('action');
        $this->assertStringContainsString('scheduled for', $c->errors()->first('action'));
        $this->assertSame('scheduled', $v->fresh()->status->value);

        app(DocumentStore::class)->store(UploadedFile::fake()->image('p.jpg'), $v, DocumentType::where('code', 'VISIT_PHOTO')->first(), $vo);
        try {
            $uw->submitVisit($v->fresh(), $vo, ['recommendation' => 'positive']);
            $this->fail('submit without check-in must fail');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Check in', $e->getMessage());
        }

        $v->update(['scheduled_for' => today()]);
        $app->customer->forceFill(['address_latitude' => 31.5200, 'address_longitude' => 74.3500])->save();
        Livewire::test(VisitShow::class, ['visit' => $v->fresh()])->set('lat', 31.5)->set('lng', 74.3)->set('accuracy', 20.0)->call('checkIn')->assertHasNoErrors();
        $v->refresh();
        $this->assertSame('in_progress', $v->status->value);
        $this->assertEquals(20.0, (float) $v->gps_accuracy_m);
        $this->assertGreaterThan(4000, $v->distance_from_address_m);
        Livewire::test(VisitShow::class, ['visit' => $v])->assertSee('recorded address – verify before relying on this report');
    }

    public function test_m18_visit_checklist_rendered_as_yes_no_lines(): void
    {
        [$t, $app, , $bm] = $this->underReview();
        $v = $this->completeVisit($t, $app, $bm);
        $this->as($bm);
        Livewire::test(VisitShow::class, ['visit' => $v])->assertSee('Met Applicant')->assertSeeHtml('text-brand-700">Yes')->assertSeeHtml('text-red-600">No')->assertDontSeeHtml('✗ Cnic Sighted');
    }

    public function test_h5_assessment_becomes_stale_and_verified_income_starts_blank(): void
    {
        [$t, $app, $g, $bm] = $this->underReview();
        $ca = $this->staff($t, 'credit_analyst');
        $uw = app(UnderwritingService::class);
        $uw->requestBureau($app, $ca);
        $uw->requestBureau($app->fresh(), $ca, 'guarantor', $g);
        $uw->assess($app->fresh(), $ca, ['monthly_income' => '90000']);   // before the field visit
        $this->completeVisit($t, $app, $bm);                                // inputs change afterwards
        $this->assertTrue($uw->isAssessmentStale($app->fresh()));
        try {
            app(ApplicationService::class)->sendForApproval($app->fresh(), $bm);
            $this->fail('stale assessment must block approval');
        } catch (DomainException $e) {
            $this->assertStringContainsString('out of date', $e->getMessage());
        }

        $this->as($ca);
        $c = Livewire::test(AppShow::class, ['application' => $app->fresh()])->assertSet('assess.monthly_income', '')->assertSee('Credit assessment is out of date');
        $c->call('runAssessment')->assertHasErrors(['assess.monthly_income' => 'required']);
        $this->assertStringContainsString('verified monthly income', $c->errors()->first('assess.monthly_income'));
        $c->set('assess.monthly_income', '95000')->assertHasNoErrors('assess.monthly_income')->call('runAssessment')->assertHasNoErrors();
        $this->assertFalse($uw->isAssessmentStale($app->fresh()));
        $this->as($bm);
        Livewire::test(AppShow::class, ['application' => $app->fresh()])->call('sendForApproval')->assertHasNoErrors();
        $this->assertSame(ApplicationStatus::PendingApproval, $app->fresh()->status);
    }

    /** Drives the case to KFS acknowledged and returns the pending guarantor signature. */
    private function kfsAcknowledged(): array
    {
        [$t, $app, $g, $bm, $cu] = $this->underReview();
        $ca = $this->staff($t, 'credit_analyst');
        $uw = app(UnderwritingService::class);
        $uw->requestBureau($app, $ca);
        $uw->requestBureau($app->fresh(), $ca, 'guarantor', $g);
        $this->completeVisit($t, $app, $bm);
        $uw->assess($app->fresh(), $ca, ['monthly_income' => '90000']);
        app(ApplicationService::class)->sendForApproval($app->fresh(), $bm);
        foreach (['cro', 'manager_operations', 'compliance_officer', 'risk_officer', 'disbursement_approver'] as $r) {
            app(ApprovalEngine::class)->approve($app->fresh()->approvalRequest, $this->staff($t, $r), 'ok');
        }
        $this->confirmAssetOwnership($app);
        app(AgreementService::class)->acknowledgeKfs($app->fresh(), $cu, 'en', '127.0.0.1', 'test');

        return [$t, $app->fresh(), $g, $cu];
    }

    public function test_h6_branch_loan_officer_confirms_wet_ink_and_guarantor_signs_via_signed_link(): void
    {
        [$t, $app, $g] = $this->kfsAcknowledged();
        $lo = $this->staff($t, 'loan_officer');
        $wet = SignatureRequest::where('loan_application_id', $app->id)->where('status', 'wet_ink_pending')->firstOrFail();
        $this->as($lo);
        Livewire::test(AppShow::class, ['application' => $app, 'tab' => 'agreement'])->assertSee('Confirm received')->assertSee('Send signing link')
            ->call('openWetInk', $wet->id)->set('comment', 'Stamp paper 12345 received at Gulberg')->call('confirmWetInk')->assertHasNoErrors();
        $this->assertSame('wet_ink_received', $wet->fresh()->status);

        $sig = SignatureRequest::where('loan_application_id', $app->id)->where('signer_type', 'guarantor')->where('signing_method', 'digital')->firstOrFail();
        $show = Livewire::test(AppShow::class, ['application' => $app, 'tab' => 'agreement'])->call('sendSigningLink', $sig->id)->assertHasNoErrors();
        $url = $show->get('signingLink');
        $this->assertStringContainsString('signature=', $url);

        // Guest guarantor flow: unsigned URL refused, signed URL works; read → OTP → sign.
        auth()->logout();
        TenantContext::clear();
        $this->get(route('guarantor.sign', ['tenant' => 'alpha', 'signatureRequest' => $sig->id]))->assertForbidden();
        $this->get($url)->assertOk()->assertSee('Send verification code');
        TenantContext::clear();
        $c = Livewire::test(GuarantorSign::class, ['tenant' => Tenant::where('code', 'alpha')->first(), 'signatureRequest' => $sig->id]);
        $c->call('sendCode')->assertHasErrors('otp');                       // must read first
        $c->set('read', true)->call('sendCode')->assertSet('codeSent', true);
        $c->set('otp', '000000')->call('sign')->assertHasErrors('otp');
        $c->set('otp', $c->get('demoOtp'))->call('sign')->assertHasNoErrors()->assertSet('done', true);
        TenantContext::set($t);
        $this->assertSame('signed', $sig->fresh()->status);
        $this->assertNotNull($sig->fresh()->document_hash);
        $this->assertNotNull(Guarantor::find($g->id)->signed_at);
    }

    public function test_h4_identity_queue_excludes_aml_only_cases_and_approval_with_open_aml_is_explained(): void
    {
        $t = $this->makeTenant('alpha');
        $amlOnly = $this->customer($t, true, 9);   // clean identity, AML potential match
        $both = $this->customer($t, true, 7);      // manual-review identity
        TenantContext::set($t);
        $bothCustomer = Customer::find($both->customer_id);
        $bothCustomer->forceFill(['aml_status' => AmlStatus::PotentialMatch])->save();
        app(DocumentStore::class)->store(UploadedFile::fake()->image('s.jpg'), $bothCustomer, DocumentType::where('code', 'SELFIE')->first(), $both);
        $co = $this->staff($t, 'compliance_officer');
        $this->as($co);

        $q = Livewire::test(KycIndex::class)->set('tab', 'identity');
        $q->assertSee(Customer::find($both->customer_id)->full_name);
        $this->assertSame(1, $q->viewData('counts')['identity']);
        Livewire::test(KycIndex::class)->set('tab', 'aml')->assertSee(Customer::find($amlOnly->customer_id)->cif_no);

        $m = Livewire::test(KycIndex::class)->call('open', $both->customer_id, 'identity')
            ->assertSee('NADRA Verisys')->assertSee('Face match')->assertSee('CNIC OCR vs. profile')->assertSee('stays')->assertSeeHtml('alt="Live selfie"');
        $m->set('notes', 'Name spelling variant confirmed')->call('decide', true)->assertDispatched('toast');
        $this->assertSame(KycStatus::PendingReview, Customer::find($both->customer_id)->kyc_status, 'KYC waits for compliance to clear AML');
    }

    public function test_m11_unknown_tab_falls_back_to_overview(): void
    {
        [$t, $app, , $bm] = $this->underReview();
        $this->as($bm);
        Livewire::withQueryParams(['tab' => 'kfs'])->test(AppShow::class, ['application' => $app])->assertSet('tab', 'overview')->assertSee('Financing request');
    }

    public function test_m2_human_validation_messages_and_errors_clear_on_edit(): void
    {
        [$t, $app, , $bm] = $this->underReview();
        $this->as($bm);
        $c = Livewire::test(AppShow::class, ['application' => $app])->call('openModal', 'guarantor')
            ->set('guarantor.name', 'X')->set('guarantor.cnic', '123')->set('guarantor.mobile', '42')->call('addGuarantor');
        $this->assertStringContainsString('12345-1234567-1', $c->errors()->first('guarantor.cnic'));
        $this->assertStringNotContainsString('guarantor.mobile', $c->errors()->first('guarantor.mobile'));
        $c->set('guarantor.cnic', '35202-1234567-1')->assertHasNoErrors('guarantor.cnic')->assertHasErrors('guarantor.mobile');
    }

    public function test_m12_dashboard_greets_person_and_shows_current_month(): void
    {
        $t = $this->makeTenant('alpha');
        $admin = $this->admin($t);   // "Alpha Admin"
        $this->as($admin);
        $c = Livewire::test(Dashboard::class)->assertSee('Alpha Admin');
        $officer = $this->staff($t, 'loan_officer', 'Sana Malik');
        $this->as($officer);
        Livewire::test(Dashboard::class)->assertSee('Sana');
        $charts = Livewire::test(Dashboard::class)->viewData('charts');
        if (isset($charts['disbursements'])) {
            $this->assertCount(6, $charts['disbursements']);
            $this->assertArrayHasKey(now()->format('Y-m'), $charts['disbursements']);
        }
    }

    public function test_m13_customer_list_masks_by_default_reveal_is_audited_and_branch_scoped(): void
    {
        $t = $this->makeTenant('alpha');
        $cu = $this->customer($t);
        TenantContext::set($t);
        $c = Customer::find($cu->customer_id);
        $admin = $this->admin($t);
        $this->as($admin);
        $list = Livewire::test(CustomerIndex::class)->assertDontSee($c->cnic)->assertSee($c->maskedCnic())->assertSee('Reveal');
        $list->call('reveal', $c->id)->assertSee($c->cnic);
        $this->assertTrue(AuditLog::where('action', 'customer.sensitive_revealed')->where('auditable_id', $c->id)->exists());

        // Branch-scoped officer: a second branch's customer is invisible and cannot be opened.
        $region = \App\Models\Region::create(['code' => 'R2', 'name' => 'R2']);
        $area = $region->areas()->create(['code' => 'A2', 'name' => 'A2']);
        $b2 = $area->branches()->create(['code' => 'B2', 'name' => 'Branch Two']);
        $lo = $this->staff($t, 'loan_officer');
        $lo->forceFill(['branch_id' => $b2->id])->save();
        $this->as($lo->fresh());
        Livewire::test(CustomerIndex::class)->assertDontSee($c->full_name)->call('reveal', $c->id)->assertForbidden();
        $this->get(route('ops.customers.show', $c))->assertForbidden();
        $c->forceFill(['branch_id' => $b2->id])->save();
        Livewire::test(CustomerIndex::class)->assertSee($c->full_name);
    }

    public function test_m14_visit_officer_picker_excludes_tenant_admin(): void
    {
        [$t, $app, , $bm] = $this->underReview();
        $vo = $this->staff($t, 'visit_officer', 'Real Visit Officer');
        $this->as($bm);
        $officers = Livewire::test(AppShow::class, ['application' => $app])->viewData('officers');
        $this->assertContains($vo->id, array_keys($officers));
        $this->assertNotContains($this->admin($t)->id, array_keys($officers));
    }
}
