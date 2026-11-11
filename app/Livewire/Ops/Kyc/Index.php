<?php

namespace App\Livewire\Ops\Kyc;

use App\Enums\AmlStatus;
use App\Enums\KycStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Models\Customer;
use App\Models\Document;
use App\Services\Kyc\OnboardingService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Manual KYC / AML review queue and identity-document expiry monitor. */
#[Layout('layouts.ops')]
class Index extends Component
{
    use HandlesActions, WithPagination;

    #[Url] public string $tab = 'identity';

    public ?int $reviewId = null;
    public string $reviewKind = 'identity';
    public string $notes = '';
    public bool $showReview = false;

    public function mount(): void
    {
        $this->need('kyc.view', 'kyc.review', 'aml.review');
        if ($this->tab === 'aml' && ! auth()->user()->hasPermission('aml.view')) {
            $this->tab = 'identity';
        }
    }

    #[On('realtime')]
    public function refreshQueue(): void {}

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function open(int $id, string $kind): void
    {
        $this->need($kind === 'aml' ? 'aml.review' : 'kyc.review');
        $this->reviewId = $id;
        $this->reviewKind = $kind;
        $this->notes = '';
        $this->showReview = true;
    }

    public function decide(bool $approve): void
    {
        $this->need($this->reviewKind === 'aml' ? 'aml.review' : 'kyc.review');
        $this->validate(['notes' => 'required|string|min:5']);
        $c = Customer::findOrFail($this->reviewId);
        $svc = app(OnboardingService::class);
        $ok = $this->attempt(fn () => $this->reviewKind === 'aml'
            ? $svc->reviewAml($c, auth()->user(), $approve, $this->notes)
            : $svc->reviewKyc($c, auth()->user(), $approve, $this->notes));
        if ($ok) {
            $this->showReview = false;
            $c->refresh();
            // H4: say exactly what happened – identity approval does not clear an open AML/PEP match.
            $this->toast(match (true) {
                $this->reviewKind === 'identity' && $approve && $c->aml_status === AmlStatus::PotentialMatch
                    => __('Identity approved for :n. KYC stays "Pending review" until Compliance clears the AML/PEP match.', ['n' => $c->full_name]),
                default => __(':n: KYC is now :k.', ['n' => $c->full_name, 'k' => $c->kyc_status->label()]),
            }, $c->kyc_status === KycStatus::Failed ? 'red' : 'green');
        }
    }

    /** Evidence for the reviewer (H4): identity images, NADRA result, face-match score and OCR-vs-profile differences. */
    private function evidence(Customer $c): array
    {
        $latest = fn (string $step) => $c->kycVerifications->firstWhere(fn ($k) => $k->step->value === $step);
        $ocr = $latest('cnic_ocr')?->result ?? [];
        $profile = ['name' => $c->full_name, 'father_name' => $c->father_husband_name, 'cnic' => $c->cnic,
            'date_of_birth' => $c->date_of_birth?->toDateString(), 'expiry_date' => $c->cnic_expiry_date?->toDateString(), 'address' => $c->permanent_address];
        $diff = [];
        foreach ($profile as $field => $value) {
            $read = $ocr[$field] ?? null;
            $diff[] = ['field' => \Illuminate\Support\Str::headline($field), 'profile' => $value, 'ocr' => $read,
                'match' => $read === null ? null : mb_strtolower(trim((string) $read)) === mb_strtolower(trim((string) $value))];
        }
        $docs = $c->documents()->with('type')->whereHas('type', fn ($q) => $q->whereIn('code', ['CNIC_FRONT', 'CNIC_BACK', 'SELFIE']))
            ->where('status', '!=', 'superseded')->latest('id')->get()->unique('document_type_id');

        return ['nadra' => $latest('nadra_verisys'), 'face' => $latest('liveness'), 'aml' => $latest('aml_screening'), 'ocr' => $diff, 'images' => $docs];
    }

    public function render()
    {
        $u = auth()->user();
        // H4: the identity queue holds only cases with an unreviewed identity step; AML-only cases live in the AML tab.
        $identitySteps = fn ($q) => $q->where('status', 'manual_review')->whereIn('step', ['nadra_verisys', 'liveness'])->whereNull('reviewed_at');
        $identity = Customer::with(['kycVerifications' => $identitySteps])
            ->where('kyc_status', KycStatus::PendingReview->value)->whereHas('kycVerifications', $identitySteps);
        $aml = Customer::with(['kycVerifications' => fn ($q) => $q->where('step', 'aml_screening')->latest('id')])
            ->where('aml_status', AmlStatus::PotentialMatch->value);
        $expiring = Document::with(['type', 'documentable'])->whereHas('type', fn ($q) => $q->where('tracks_expiry', true))
            ->where('status', '!=', 'superseded')->whereNotNull('expires_on')->whereDate('expires_on', '<=', now()->addDays(45))->orderBy('expires_on');

        return view('livewire.ops.kyc.index', [
            'canAml' => $u->hasPermission('aml.view'),
            'counts' => ['identity' => (clone $identity)->count(), 'aml' => $u->hasPermission('aml.view') ? (clone $aml)->count() : 0, 'expiry' => (clone $expiring)->count()],
            'rows' => match ($this->tab) {
                'aml' => $u->hasPermission('aml.view') ? $aml->latest('updated_at')->paginate(15) : collect(),
                'expiry' => $expiring->paginate(15),
                default => $identity->latest('updated_at')->paginate(15),
            },
            'subject' => $subject = ($this->reviewId ? Customer::with(['kycVerifications' => fn ($q) => $q->latest('id')])->find($this->reviewId) : null),
            'evidence' => $subject ? $this->evidence($subject) : [],
            'sensitive' => $u->hasPermission('customers.view_sensitive'),
        ]);
    }
}
