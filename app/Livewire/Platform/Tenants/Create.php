<?php

namespace App\Livewire\Platform\Tenants;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Tenant;
use App\Services\Identity\AuthService;
use App\Services\Platform\PlatformSettings;
use App\Services\Tenancy\TenantProvisioner;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Provisioning workflow (LMS §12.2): tenant record + default configuration + initial administrator. */
#[Layout('layouts.platform')]
class Create extends Component
{
    use HandlesActions;

    public array $form = [
        'code' => '', 'name' => '', 'legal_name' => '', 'registration_no' => '', 'license_ref' => '', 'ntn' => '',
        'primary_contact_name' => '', 'primary_contact_email' => '', 'primary_contact_phone' => '', 'address' => '', 'city' => '',
        'support_phone' => '', 'support_email' => '', 'primary_color' => '#15803d', 'tagline' => '', 'tagline_ur' => '',
        'default_locale' => 'en', 'is_demo' => true, 'wallet_safeguarding_model' => 'tenant_safeguarded',
    ];

    public array $features = [];

    public string $admin_name = '';

    public string $admin_email = '';

    public string $admin_password = '';

    public function mount(): void
    {
        $this->need('platform.tenants.manage');
        $this->features = PlatformSettings::get('default_features') ?? TenantProvisioner::DEFAULT_FEATURES;
        $this->admin_password = 'Init#'.random_int(100000, 999999).'aZ';
    }

    protected function rules(): array
    {
        return [
            'form.code' => ['required', 'regex:/^[a-z][a-z0-9-]{1,38}$/', 'unique:tenants,code'],
            'form.name' => 'required|string|max:120',
            'form.legal_name' => 'nullable|string|max:190',
            'form.registration_no' => 'nullable|string|max:60',
            'form.license_ref' => 'nullable|string|max:80',
            'form.ntn' => 'nullable|string|max:20',
            'form.primary_contact_name' => 'nullable|string|max:120',
            'form.primary_contact_email' => 'nullable|email',
            'form.primary_contact_phone' => 'nullable|string|max:20',
            'form.address' => 'nullable|string|max:190',
            'form.city' => 'required|string|max:60',
            'form.support_phone' => 'nullable|string|max:20',
            'form.support_email' => 'nullable|email',
            'form.primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'form.tagline' => 'nullable|string|max:190',
            'form.tagline_ur' => 'nullable|string|max:190',
            'form.default_locale' => 'required|in:en,ur',
            'form.is_demo' => 'boolean',
            'form.wallet_safeguarding_model' => 'required|in:tenant_safeguarded,platform_pooled',
            'features' => 'array',
            'features.*' => 'in:'.implode(',', array_keys(Tenant::FEATURES)),
            'admin_name' => 'required|string|max:120',
            'admin_email' => 'required|email',
            'admin_password' => AuthService::passwordRules(null),
        ];
    }

    public function save(TenantProvisioner $provisioner)
    {
        $this->need('platform.tenants.manage');
        $this->form['code'] = strtolower(trim($this->form['code']));
        $this->admin_password_confirmation = $this->admin_password; // generated/typed by the platform admin, shown once
        $this->validate($this->rules(), [], ['form.code' => 'code', 'admin_password' => 'temporary password']);

        $tenant = $this->attempt(fn () => $provisioner->provision(
            array_merge($this->form, ['features' => array_values($this->features)]),
            ['name' => $this->admin_name, 'email' => $this->admin_email, 'password' => $this->admin_password, 'must_change_password' => true],
            auth()->user(),
        ), 'Tenant provisioned.');

        if ($tenant instanceof Tenant) {
            session()->flash('success', "Tenant {$tenant->name} provisioned. Initial admin {$this->admin_email} must change the temporary password at first sign-in.");
            session()->flash('provisioned_password', $this->admin_password);

            return $this->redirect(route('platform.tenants.show', $tenant), navigate: true);
        }
    }

    public string $admin_password_confirmation = '';

    public function render()
    {
        return view('livewire.platform.tenants.create');
    }
}
