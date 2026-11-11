<?php

namespace App\Livewire\Ops\Customers;

use App\Enums\ConsentType;
use App\Livewire\Concerns\HandlesActions;
use App\Models\Branch;
use App\Models\DocumentType;
use App\Services\Documents\DocumentStore;
use App\Services\Kyc\CustomerAdminService;
use App\Services\Kyc\OnboardingService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Staff-assisted (branch) onboarding: CIF creation, documents, in-branch consents and optional e-KYC run. */
#[Layout('layouts.ops')]
class Create extends Component
{
    use HandlesActions, WithFileUploads;

    public array $form = [
        'full_name' => '', 'father_husband_name' => '', 'cnic' => '', 'cnic_expiry_date' => '', 'date_of_birth' => '', 'gender' => '', 'marital_status' => '',
        'mobile' => '', 'email' => '', 'current_address' => '', 'permanent_address' => '', 'city' => '', 'client_type' => 'individual',
        'occupation' => '', 'employment_type' => '', 'employer_name' => '', 'business_name' => '', 'business_type' => '', 'income_source' => '',
        'monthly_income' => '', 'other_income' => '', 'existing_obligations' => '', 'branch_id' => '', 'preferred_locale' => 'en',
    ];

    public array $nok = ['name' => '', 'relation' => '', 'mobile' => '', 'cnic' => ''];

    public array $consents = [];

    public $cnicFront;

    public $cnicBack;

    public $photo;

    public bool $runKyc = true;

    public string $kycScenario = 'auto';

    public function mount(): void
    {
        $this->need('customers.create');
        $this->form['branch_id'] = (string) (auth()->user()->branch_id ?? '');
        foreach (ConsentType::cases() as $c) {
            $this->consents[$c->value] = in_array($c, [ConsentType::TermsOfUse, ConsentType::PrivacyPolicy], true);
        }
    }

    protected function rules(): array
    {
        return [
            'form.full_name' => 'required|string|min:3|max:120',
            'form.father_husband_name' => 'nullable|string|max:120',
            'form.cnic' => ['required', 'regex:/^\d{5}-\d{7}-\d$/'],
            'form.cnic_expiry_date' => 'nullable|date',
            'form.date_of_birth' => 'required|date|before:-18 years',
            'form.gender' => 'required|in:male,female,other',
            'form.marital_status' => 'nullable|in:single,married,widowed,divorced',
            'form.mobile' => ['required', 'regex:/^(\+92|92|0)3\d{9}$/'],
            'form.email' => 'nullable|email',
            'form.current_address' => 'required|string|max:250',
            'form.permanent_address' => 'nullable|string|max:250',
            'form.city' => 'required|string|max:60',
            'form.client_type' => 'required|in:individual,corporate,vendor_referred,staff',
            'form.employment_type' => 'required|in:salaried,self_employed,business,unemployed',
            'form.monthly_income' => 'required|numeric|min:0|max:100000000',
            'form.other_income' => 'nullable|numeric|min:0',
            'form.existing_obligations' => 'nullable|numeric|min:0',
            'form.branch_id' => 'required|exists:branches,id',
            'nok.name' => 'nullable|string|max:120',
            'nok.relation' => 'required_with:nok.name|nullable|string|max:30',
            'cnicFront' => 'nullable|image|max:5120',
            'cnicBack' => 'nullable|image|max:5120',
            'photo' => 'nullable|image|max:5120',
            'consents.terms_of_use' => 'accepted',
            'consents.privacy_policy' => 'accepted',
        ];
    }

    /** M3: an error disappears as soon as the user edits that field. */
    public function updated(string $property): void
    {
        $this->resetErrorBag($property);
    }

    protected $validationAttributes = ['form.cnic' => 'CNIC', 'form.date_of_birth' => 'date of birth', 'form.monthly_income' => 'monthly income'];

    public function save(CustomerAdminService $admin, OnboardingService $onboarding, DocumentStore $docs)
    {
        $this->need('customers.create');
        $this->validate();
        abort_unless(Branch::whereKey($this->form['branch_id'])->exists(), 403);

        $customer = $this->attempt(function () use ($admin, $onboarding, $docs) {
            $data = array_map(fn ($v) => $v === '' ? null : $v, $this->form);
            $data['next_of_kin'] = array_filter($this->nok) ? $this->nok : null;
            $user = auth()->user();

            $c = DB::transaction(function () use ($data, $admin, $onboarding, $docs, $user) {
                $c = $admin->create($data, $user);
                $onboarding->recordConsents($c, $this->consents, $c->preferred_locale ?: 'en', request()->ip(), 'Captured in branch by '.$user->name);
                foreach (['cnicFront' => 'CNIC_FRONT', 'cnicBack' => 'CNIC_BACK', 'photo' => 'SELFIE'] as $prop => $code) {
                    if ($this->{$prop}) {
                        $docs->store($this->{$prop}, $c, DocumentType::where('code', $code)->first(), $user, null, $code === 'CNIC_FRONT' ? $c->cnic_expiry_date?->toDateString() : null);
                    }
                }

                return $c;
            });
            if ($this->runKyc && $this->cnicFront && ($this->consents['nadra_verification'] ?? false)) {
                $status = $onboarding->runEkyc($c->fresh(), \App\Services\Payments\Simulation::enabled() ? $this->kycScenario : 'auto', $user);
                session()->flash('info', __('e-KYC result: :s', ['s' => $status->label()]));
            }

            return $c;
        }, __('Customer created.'));

        if ($customer) {
            return $this->redirect(route('ops.customers.show', $customer), navigate: true);
        }
    }

    public function render()
    {
        return view('livewire.ops.customers.create', [
            'branches' => Branch::orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }
}
