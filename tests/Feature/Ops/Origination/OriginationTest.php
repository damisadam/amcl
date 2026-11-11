<?php

namespace Tests\Feature\Ops\Origination;

use App\Enums\ApplicationStatus;
use App\Enums\KycStatus;
use App\Livewire\Ops\Applications\Create as AppCreate;
use App\Livewire\Ops\Applications\Index as AppIndex;
use App\Livewire\Ops\Applications\Show as AppShow;
use App\Livewire\Ops\Approvals\Index as Approvals;
use App\Livewire\Ops\Customers\Create as CustomerCreate;
use App\Livewire\Ops\Customers\Index as CustomerIndex;
use App\Livewire\Ops\Customers\Show as CustomerShow;
use App\Livewire\Ops\Dashboard;
use App\Livewire\Ops\FieldVisits\Show as VisitShow;
use App\Livewire\Ops\Kyc\Index as KycIndex;
use App\Livewire\Ops\Leads\Index as Leads;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\FieldVisit;
use App\Models\Lead;
use App\Models\LoanApplication;
use App\Models\Product;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class OriginationTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function as(User $u): static
    {
        TenantContext::set($u->tenant);
        $this->actingAs($u);

        return $this;
    }

    /** Draft application for a KYC-passed customer with a vendor product; returns [tenant, app]. */
    private function draft(): array
    {
        $t = $this->makeTenant('alpha');
        $vendor = $this->vendor($t);
        $product = $this->product($t);
        $cu = $this->customer($t);
        $lo = $this->staff($t, 'loan_officer');
        $this->as($lo);
        Livewire::test(AppCreate::class)
            ->call('pickCustomer', $cu->customer_id)
            ->set('product_id', (string) $product->id)
            ->set('vendor_id', (string) $vendor->id)
            ->set('vendor_item_id', (string) $vendor->items()->first()->id)
            ->set('tenure', '12')->set('purpose', 'Daily commute')
            ->call('save')->assertHasNoErrors()->assertRedirect();

        return [$t, LoanApplication::latest('id')->first(), $lo];
    }

    public function test_dashboard_renders_role_aware_widgets(): void
    {
        $t = $this->makeTenant('alpha');
        $this->as($this->admin($t));
        Livewire::test(Dashboard::class)->assertOk()->assertSee('Outstanding principal')->assertSee('KYC pending review');

        $vo = $this->staff($t, 'visit_officer');
        $this->as($vo);
        Livewire::test(Dashboard::class)->assertOk()->assertSee('My field visits')->assertDontSee('Outstanding principal');

        $this->as($this->staff($t, 'recovery_agent'));
        Livewire::test(Dashboard::class)->assertOk()->assertSee('My recovery cases');
    }

    public function test_staff_onboards_customer_and_runs_ekyc(): void
    {
        $t = $this->makeTenant('alpha');
        $this->as($this->staff($t, 'branch_manager'));
        Livewire::test(CustomerCreate::class)
            ->set('form.full_name', 'Branch Customer')->set('form.cnic', '35202-7654321-2')->set('form.date_of_birth', '1988-02-02')
            ->set('form.gender', 'female')->set('form.mobile', '03211234567')->set('form.current_address', 'Model Town')->set('form.city', 'Lahore')
            ->set('form.employment_type', 'salaried')->set('form.monthly_income', '70000')
            ->set('consents.nadra_verification', true)->set('consents.credit_bureau', true)
            ->set('cnicFront', UploadedFile::fake()->image('front.jpg'))
            ->call('save')->assertHasNoErrors();

        $c = Customer::where('mobile', '03211234567')->firstOrFail();
        $this->assertSame('branch', $c->onboarding_channel);
        $this->assertSame(KycStatus::Passed, $c->kyc_status);
        $this->assertGreaterThanOrEqual(4, $c->consents()->where('granted', true)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.created_by_staff', 'auditable_id' => $c->id]);
    }

    public function test_loan_officer_cannot_see_aml_filters_and_sensitive_cnic_is_masked(): void
    {
        $t = $this->makeTenant('alpha');
        $cu = $this->customer($t);
        $this->as($this->staff($t, 'loan_officer'));
        $c = Customer::find($cu->customer_id);
        Livewire::test(CustomerIndex::class)->assertOk()->assertSee($c->maskedCnic())->assertDontSee($c->cnic)->assertDontSee('All AML');
        Livewire::test(CustomerShow::class, ['customer' => $c])->set('tab', 'kyc')->assertDontSee('AML Screening');

        $this->as($this->staff($t, 'compliance_officer'));
        Livewire::test(CustomerShow::class, ['customer' => $c])->set('tab', 'kyc')->assertSee('AML Screening');
    }

    public function test_manual_kyc_and_aml_review_queue(): void
    {
        $t = $this->makeTenant('alpha');
        $review = $this->customer($t, true, 7);   // partial NADRA match → manual review
        $aml = $this->customer($t, true, 9);      // AML potential match
        $this->assertSame(KycStatus::PendingReview, Customer::find($review->customer_id)->kyc_status);

        $this->as($this->staff($t, 'compliance_officer'));
        Livewire::test(KycIndex::class)->assertSee('Identity review')
            ->call('open', $review->customer_id, 'identity')->set('notes', 'Name spelling verified with CNIC')->call('decide', true)->assertHasNoErrors();
        $this->assertSame(KycStatus::Passed, Customer::find($review->customer_id)->kyc_status);

        Livewire::test(KycIndex::class)->set('tab', 'aml')->call('open', $aml->customer_id, 'aml')->set('notes', 'False positive – different DOB')->call('decide', true);
        $this->assertSame('cleared_after_review', Customer::find($aml->customer_id)->aml_status->value);

        // A loan officer may view the queue (kyc.view) but cannot decide reviews; a visit officer cannot open it.
        $this->as($this->staff($t, 'loan_officer'));
        Livewire::test(KycIndex::class)->assertOk()->call('open', $review->customer_id, 'identity')->assertForbidden();
        $this->as($this->staff($t, 'visit_officer'));
        Livewire::test(KycIndex::class)->assertForbidden();
    }

    public function test_lead_capture_and_conversion(): void
    {
        $t = $this->makeTenant('alpha');
        $p = $this->product($t, ['requires_vendor' => false, 'disbursement_mode' => 'customer_bank']);
        $this->as($this->staff($t, 'loan_officer'));
        Livewire::test(Leads::class)->set('form.name', 'Walk In')->set('form.mobile', '03331112222')->set('form.product_id', (string) $p->id)
            ->set('form.requested_amount', '120000')->call('create')->assertHasNoErrors();
        $lead = Lead::firstOrFail();
        Livewire::test(Leads::class)->call('openMove', $lead->id)->set('moveTo', 'contacted')->call('move');
        $this->assertSame('contacted', $lead->fresh()->status);
        Livewire::test(Leads::class)->call('convert', $lead->id)->assertRedirect();
        $lead->refresh();
        $this->assertSame('converted', $lead->status);
        $this->assertSame(ApplicationStatus::Draft, LoanApplication::find($lead->converted_application_id)->status);
    }

    public function test_full_origination_through_approval_inbox(): void
    {
        [$t, $app, $lo] = $this->draft();
        $this->assertSame(ApplicationStatus::Draft, $app->status);
        $this->as($lo);
        Livewire::test(AppShow::class, ['application' => $app])->call('submit')->assertHasNoErrors();
        $this->assertSame(ApplicationStatus::Submitted, $app->fresh()->status);

        $bm = $this->staff($t, 'branch_manager');
        $ca = $this->staff($t, 'credit_analyst');
        $vo = $this->staff($t, 'visit_officer');
        $this->as($bm);
        Livewire::test(AppShow::class, ['application' => $app])->call('startReview');
        $this->as($ca);
        Livewire::test(AppShow::class, ['application' => $app])->call('requestBureau', 'applicant')->assertHasNoErrors();
        $this->as($bm);
        Livewire::test(AppShow::class, ['application' => $app])->call('openModal', 'visit')->set('visit.officer', (string) $vo->id)
            ->set('visit.date', today()->toDateString())->call('scheduleVisit')->assertHasNoErrors();
        $visit = FieldVisit::firstOrFail();

        $this->as($vo);
        Livewire::test(VisitShow::class, ['visit' => $visit])->set('lat', 31.5)->set('lng', 74.3)->set('accuracy', 20.0)->call('checkIn')->assertHasNoErrors()
            ->set('photo', UploadedFile::fake()->image('house.jpg'))->call('uploadProof')
            ->set('findings.recommendation', 'positive')->set('findings.remarks', 'Residence verified')->call('submit')->assertHasNoErrors();
        $this->assertSame('completed', $visit->fresh()->status->value);

        $this->as($ca);
        Livewire::test(AppShow::class, ['application' => $app])->set('assess.monthly_income', '85000')->call('runAssessment')->assertHasNoErrors();
        $this->assertNotNull($app->fresh()->credit_grade);
        $this->as($bm);
        Livewire::test(AppShow::class, ['application' => $app])->call('sendForApproval')->assertHasNoErrors();
        $this->assertSame(ApplicationStatus::PendingApproval, $app->fresh()->status);

        // The requester (branch manager) cannot approve his own submission even with a stage role.
        foreach (['cro', 'manager_operations', 'compliance_officer', 'risk_officer', 'disbursement_approver'] as $role) {
            $approver = $this->staff($t, $role);
            $this->as($approver);
            $req = $app->fresh()->approvalRequest;
            Livewire::test(Approvals::class)->assertSee($app->application_no)
                ->call('select', $req->id)->set('comment', 'Reviewed and in order')->call('decide', 'approve')->assertHasNoErrors();
        }
        $this->assertSame(ApplicationStatus::Approved, $app->fresh()->status);
        $this->assertNotNull($app->fresh()->kfs);
    }

    public function test_reject_requires_reason_and_return_creates_customer_visible_objection(): void
    {
        [$t, $app, $lo] = $this->draft();
        $this->as($lo);
        Livewire::test(AppShow::class, ['application' => $app])->call('submit');
        $bm = $this->staff($t, 'branch_manager');
        $this->as($bm);
        Livewire::test(AppShow::class, ['application' => $app])->call('openModal', 'return')->set('reason', '')->call('returnCase')->assertHasErrors('reason');
        Livewire::test(AppShow::class, ['application' => $app])->call('openModal', 'return')->set('reason', 'Salary slip is unreadable')->call('returnCase')->assertHasNoErrors();
        $this->assertSame(ApplicationStatus::Returned, $app->fresh()->status);
        $this->assertDatabaseHas('objections', ['loan_application_id' => $app->id, 'visible_to_customer' => true]);

        $cro = $this->staff($t, 'cro');
        $this->as($cro);
        Livewire::test(AppShow::class, ['application' => $app])->call('openModal', 'reject')->set('rejectCode', 'incomplete_documents')->set('reason', 'Documents not provided')->call('reject');
        $this->assertSame(ApplicationStatus::Rejected, $app->fresh()->status);
        $this->assertSame('incomplete_documents', $app->fresh()->rejection_code);
    }

    public function test_authorization_denials(): void
    {
        [$t, $app] = $this->draft();
        // wrong role: visit officer cannot open application queue or a case file
        $this->as($this->staff($t, 'visit_officer'));
        Livewire::test(AppIndex::class)->assertForbidden();
        Livewire::test(AppShow::class, ['application' => $app])->assertForbidden();
        // credit analyst cannot submit on behalf
        $this->as($this->staff($t, 'credit_analyst'));
        Livewire::test(AppShow::class, ['application' => $app])->call('submit')->assertForbidden();

        // other branch: loan officer at another branch without view_all cannot open the case
        $other = TenantContext::run($t, function () {
            $area = \App\Models\Area::first();

            return Branch::create(['area_id' => $area->id, 'code' => 'B2', 'name' => 'Branch Two']);
        });
        $lo2 = $this->staff($t, 'loan_officer');
        $lo2->forceFill(['branch_id' => $other->id])->save();
        $this->as($lo2->fresh());
        Livewire::test(AppShow::class, ['application' => $app])->assertForbidden();

        // other tenant: route model binding returns 404
        $b = $this->makeTenant('bravo');
        $this->actingAs($this->admin($b))->get(route('ops.applications.show', $app->id))->assertNotFound();
        $this->actingAs($this->admin($b))->get(route('ops.customers.show', $app->customer_id))->assertNotFound();
    }

    public function test_pages_render_over_http(): void
    {
        [$t, $app] = $this->draft();
        $admin = $this->admin($t);
        foreach (['ops.dashboard', 'ops.customers.index', 'ops.customers.create', 'ops.kyc.index', 'ops.leads.index', 'ops.applications.index', 'ops.visits.index'] as $r) {
            $this->actingAs($admin)->get(route($r))->assertOk();
        }
        $this->actingAs($this->staff($t, 'loan_officer'))->get(route('ops.applications.create'))->assertOk();
        // Approval decisions are operational: Tenant Admin no longer holds approvals.act (H7); an approver does.
        $this->actingAs($this->staff($t, 'cro'))->get(route('ops.approvals.index'))->assertOk();
        $this->actingAs($admin)->get(route('ops.applications.show', $app))->assertOk()->assertSee($app->application_no);
        $this->actingAs($admin)->get(route('ops.customers.show', $app->customer_id))->assertOk();
    }

    public function test_every_tab_renders(): void
    {
        [$t, $app] = $this->draft();
        $this->as($this->admin($t));
        foreach (['overview', 'documents', 'guarantors', 'bureau', 'visits', 'assessment', 'objections', 'approvals', 'agreement', 'timeline'] as $tab) {
            Livewire::test(AppShow::class, ['application' => $app])->set('tab', $tab)->assertOk();
        }
        foreach (['profile', 'kyc', 'documents', 'financing', 'wallet', 'notes', 'consents', 'changes', 'admin'] as $tab) {
            Livewire::test(CustomerShow::class, ['customer' => $app->customer])->set('tab', $tab)->assertOk();
        }
        foreach (['identity', 'aml', 'expiry'] as $tab) {
            Livewire::test(KycIndex::class)->set('tab', $tab)->assertOk();
        }
        $this->as($this->staff($t, 'cro'));
        foreach (['mine', 'overdue', 'all', 'completed'] as $tab) {
            Livewire::test(Approvals::class)->set('tab', $tab)->assertOk();
        }
    }
}
