<?php

namespace App\Livewire\Ops\FieldVisits;

use App\Enums\FieldVisitStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Models\DocumentType;
use App\Models\FieldVisit;
use App\Models\User;
use App\Policies\RecordAccess;
use App\Services\Audit\Auditor;
use App\Services\Documents\DocumentStore;
use App\Services\Lending\UnderwritingService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Visit form: GPS check-in, checklist, proof photos, remarks and recommendation (LMS §7.1–7.5). */
#[Layout('layouts.ops')]
class Show extends Component
{
    use HandlesActions, WithFileUploads;

    public FieldVisit $visit;

    public ?float $lat = null;
    public ?float $lng = null;
    public ?float $accuracy = null;
    public $photo;
    public array $findings = ['address_match' => '1', 'living_conditions' => '', 'business_conditions' => '', 'neighbor_verification' => '', 'remarks' => '', 'recommendation' => ''];
    public array $checklist = ['met_applicant' => false, 'cnic_sighted' => false, 'residence_owned' => false, 'business_operational' => false, 'asset_present' => false, 'neighbour_confirmed' => false];

    public string $rescheduleDate = '';
    public string $reassignTo = '';
    public string $cancelReason = '';

    /** M2: human field names in validation messages. */
    protected function validationAttributes(): array
    {
        return ['findings.address_match' => 'address match', 'findings.recommendation' => 'recommendation', 'findings.remarks' => 'remarks', 'findings.neighbor_verification' => 'neighbour verification', 'findings.living_conditions' => 'living conditions', 'findings.business_conditions' => 'business conditions', 'photo' => 'proof photo', 'rescheduleDate' => 'new visit date', 'reassignTo' => 'officer', 'cancelReason' => 'cancellation reason'];
    }

    /** M3: an error disappears as soon as the user edits that field. */
    public function updated(string $property): void
    {
        $this->resetErrorBag($property);
    }

    public function mount(FieldVisit $visit): void
    {
        $u = auth()->user();
        abort_unless($u->hasAnyPermission(['field_visits.view', 'field_visits.perform', 'field_visits.assign']), 403);
        abort_unless(RecordAccess::canViewVisit($u, $visit) || $u->hasPermission('field_visits.assign'), 403);
        $this->visit = $visit;
        $this->rescheduleDate = $visit->scheduled_for->toDateString();
    }

    private function assertOfficer(): void
    {
        $this->need('field_visits.perform');
        abort_unless((int) $this->visit->assigned_to === (int) auth()->id(), 403);
    }

    public function checkIn(): void
    {
        $this->assertOfficer();
        if ($this->attempt(fn () => app(UnderwritingService::class)->checkIn($this->visit->fresh(), auth()->user(), $this->lat, $this->lng, $this->accuracy), __('Checked in at the location.'))) {
            $this->visit->refresh();
        }
    }

    public function uploadProof(): void
    {
        $this->assertOfficer();
        $this->validate(['photo' => 'required|file|max:5120|mimes:jpg,jpeg,png,webp,pdf']);
        if ($this->attempt(fn () => app(DocumentStore::class)->store($this->photo, $this->visit, DocumentType::where('code', 'VISIT_PHOTO')->first(), auth()->user(),
            'Visit proof '.now()->format('d M H:i').($this->lat ? " @ {$this->lat},{$this->lng}" : '')), __('Proof uploaded.'))) {
            $this->reset('photo');
        }
    }

    public function submit(): void
    {
        $this->assertOfficer();
        $this->validate([
            'findings.address_match' => 'required|in:0,1', 'findings.recommendation' => 'required|in:positive,negative,follow_up',
            'findings.remarks' => 'required|string|min:5|max:2000', 'findings.neighbor_verification' => 'nullable|string|max:500',
            'findings.living_conditions' => 'nullable|in:good,average,poor', 'findings.business_conditions' => 'nullable|in:good,average,poor,not_applicable',
        ]);
        $data = $this->findings;
        $data['address_match'] = (bool) $data['address_match'];
        $data['checklist'] = $this->checklist;
        if ($this->attempt(fn () => app(UnderwritingService::class)->submitVisit($this->visit->fresh(), auth()->user(), $data), __('Visit report submitted.'))) {
            $this->visit->refresh();
        }
    }

    public function reschedule(): void
    {
        $this->need('field_visits.assign');
        $this->validate(['rescheduleDate' => 'required|date|after_or_equal:today', 'reassignTo' => 'nullable|integer']);
        abort_if($this->visit->status !== FieldVisitStatus::Scheduled, 422);
        $changes = ['scheduled_for' => $this->rescheduleDate];
        if ($this->reassignTo) {
            $officer = User::where('type', 'staff')->findOrFail($this->reassignTo);
            if (! $officer->hasPermission('field_visits.perform') || $officer->roles->every(fn ($r) => $r->key === 'tenant_admin')) {
                $this->addError('reassignTo', __('Selected user is not a visit officer.'));

                return;
            }
            $changes['assigned_to'] = $officer->id;
        }
        $old = $this->visit->only(['scheduled_for', 'assigned_to']);
        $this->visit->update($changes);
        Auditor::record('field_visit.rescheduled', $this->visit, $old, $changes);
        $this->toast(__('Visit updated.'));
    }

    public function cancel(): void
    {
        $this->need('field_visits.assign');
        $this->validate(['cancelReason' => 'required|string|min:5']);
        $this->attempt(function () {
            $this->visit->status->assertCanTransitionTo(FieldVisitStatus::Cancelled, 'Field visit');
            $this->visit->update(['status' => FieldVisitStatus::Cancelled, 'remarks' => 'Cancelled: '.$this->cancelReason]);
            Auditor::record('field_visit.cancelled', $this->visit, [], [], $this->cancelReason);
        }, __('Visit cancelled.'));
    }

    public function render()
    {
        $u = auth()->user();
        $v = $this->visit->load(['customer', 'application.product', 'officer', 'documents.type']);

        return view('livewire.ops.field-visits.show', [
            'v' => $v,
            'isOfficer' => (int) $v->assigned_to === (int) $u->id && $u->hasPermission('field_visits.perform'),
            // M14: real visit officers only (not Tenant Admin by virtue of "all permissions"), same branch first.
            'officers' => $u->hasPermission('field_visits.assign') ? User::with('branch')->where('type', 'staff')->where('status', 'active')
                ->whereHas('roles', fn ($r) => $r->where('key', '!=', 'tenant_admin')->whereHas('permissions', fn ($p) => $p->where('key', 'field_visits.perform')))
                ->orderByRaw('branch_id = ? desc', [$v->application?->branch_id ?? $v->customer->branch_id ?? 0])->orderBy('name')->get()
                ->mapWithKeys(fn ($o) => [$o->id => $o->name.($o->branch ? ' · '.$o->branch->name : '')])->all() : [],
            'isToday' => $v->scheduled_for->isSameDay(today()),
            'maxAccuracy' => UnderwritingService::MAX_GPS_ACCURACY_M,
            'distanceFlag' => UnderwritingService::ADDRESS_DISTANCE_FLAG_M,
        ]);
    }
}
