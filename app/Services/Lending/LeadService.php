<?php

namespace App\Services\Lending;

use App\Exceptions\DomainException;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LoanApplication;
use App\Models\Product;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Kyc\CustomerAdminService;
use App\Services\Kyc\OnboardingService;
use App\Services\Support\Numbering;
use Illuminate\Support\Facades\DB;

/** Loan lead arrival (LMS §6.1): capture, pipeline, assignment and conversion. */
class LeadService
{
    public const STATUSES = ['new', 'contacted', 'qualified', 'converted', 'dropped'];

    private const FLOW = [
        'new' => ['contacted', 'qualified', 'dropped'],
        'contacted' => ['qualified', 'dropped'],
        'qualified' => ['converted', 'dropped'],
        'dropped' => ['new'],
        'converted' => [],
    ];

    public function create(array $data, User $by): Lead
    {
        $data['mobile'] = OnboardingService::normaliseMobile($data['mobile']);
        $lead = Lead::create($data + ['lead_no' => Numbering::next('lead'), 'status' => 'new', 'branch_id' => $by->branch_id, 'assigned_to' => $by->id]);
        Auditor::record('lead.created', $lead, [], ['lead_no' => $lead->lead_no, 'channel' => $lead->channel]);

        return $lead;
    }

    public function moveTo(Lead $lead, string $status, User $by, ?string $note = null): Lead
    {
        if (! in_array($status, self::FLOW[$lead->status] ?? [], true)) {
            throw new DomainException("A {$lead->status} lead cannot move to {$status}.");
        }
        if ($status === 'converted') {
            throw new DomainException('Use "Convert to application" to convert a lead.');
        }
        $old = $lead->status;
        $lead->update(['status' => $status, 'notes' => trim(($lead->notes ? $lead->notes."\n" : '').($note ? now()->format('d M').': '.$note : ''))]);
        Auditor::record('lead.status_changed', $lead, ['status' => $old], ['status' => $status], $note);

        return $lead;
    }

    public function assign(Lead $lead, User $to, User $by): Lead
    {
        if (! $to->isStaff() || (int) $to->tenant_id !== (int) $by->tenant_id) {
            throw new DomainException('Leads can only be assigned to staff of this institution.');
        }
        $lead->update(['assigned_to' => $to->id]);
        Auditor::record('lead.assigned', $lead, [], ['to' => $to->name]);

        return $lead;
    }

    /** Converts to a draft application, creating the customer record if needed. */
    public function convert(Lead $lead, User $by): LoanApplication
    {
        if (! in_array($lead->status, ['new', 'contacted', 'qualified'], true)) {
            throw new DomainException('Only open leads can be converted.');
        }
        if (! $lead->product_id) {
            throw new DomainException('Select the product the lead is interested in first.');
        }

        return DB::transaction(function () use ($lead, $by) {
            $customer = Customer::where('mobile', $lead->mobile)->first()
                ?? ($lead->cnic ? Customer::where('cnic', $lead->cnic)->first() : null)
                ?? app(CustomerAdminService::class)->create(['full_name' => $lead->name, 'mobile' => $lead->mobile, 'cnic' => $lead->cnic], $by, 'branch');
            $product = Product::findOrFail($lead->product_id);
            $app = app(ApplicationService::class)->createDraft($customer, $product, array_filter([
                'requested_amount' => $lead->requested_amount, 'vendor_id' => $lead->vendor_id,
            ]), $by, $lead->channel === 'vendor' ? 'vendor' : 'branch');
            $app->forceFill(['lead_id' => $lead->id])->save();
            $lead->update(['status' => 'converted', 'converted_application_id' => $app->id]);
            Auditor::record('lead.converted', $lead, [], ['application' => $app->application_no]);

            return $app;
        });
    }
}
