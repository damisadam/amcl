<?php

namespace App\Livewire\Platform\Tenants;

use App\Enums\TenantStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Models\Tenant;
use App\Services\Platform\PlatformMetrics;
use App\Services\Platform\TenantAdministration;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.platform')]
class Show extends Component
{
    use HandlesActions;

    public Tenant $tenant;

    public string $tab = 'profile';

    public array $form = [];

    public array $features = [];

    public bool $showStatus = false;

    public string $targetStatus = '';

    public string $statusReason = '';

    public ?array $newCredentials = null;

    public function mount(Tenant $tenant): void
    {
        $this->need('platform.tenants.view');
        $this->tenant = $tenant;
        $this->fillForm();
        $this->newCredentials = session('provisioned_password') ? ['email' => null, 'password' => session('provisioned_password')] : null;
    }

    private function fillForm(): void
    {
        $this->form = collect($this->tenant->only(TenantAdministration::EDITABLE))->map(fn ($v) => $v ?? '')->all();
        $this->form['is_demo'] = (bool) $this->tenant->is_demo;
        $this->features = $this->tenant->features ?? [];
    }

    public function saveProfile(TenantAdministration $admin): void
    {
        $this->need('platform.tenants.manage');
        $this->validate([
            'form.name' => 'required|string|max:120', 'form.legal_name' => 'nullable|string|max:190', 'form.license_ref' => 'nullable|string|max:80',
            'form.city' => 'required|string|max:60', 'form.primary_contact_email' => 'nullable|email', 'form.support_email' => 'nullable|email',
            'form.primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'form.default_locale' => 'required|in:en,ur',
            'form.wallet_safeguarding_model' => 'required|in:tenant_safeguarded,platform_pooled',
        ]);
        $data = collect($this->form)->map(fn ($v) => $v === '' ? null : $v)->all();
        $this->attempt(fn () => $admin->update($this->tenant, $data, auth()->user()), 'Tenant profile updated.');
    }

    public function saveFeatures(TenantAdministration $admin): void
    {
        $this->need('platform.tenants.manage');
        $this->attempt(fn () => $admin->setFeatures($this->tenant, $this->features, auth()->user()), 'Feature entitlements updated.');
    }

    public function askStatus(string $status): void
    {
        $this->need('platform.tenants.suspend');
        $this->targetStatus = TenantStatus::from($status)->value;
        $this->statusReason = '';
        $this->showStatus = true;
    }

    public function changeStatus(TenantAdministration $admin): void
    {
        $this->need('platform.tenants.suspend');
        if ($this->attempt(fn () => $admin->changeStatus($this->tenant, TenantStatus::from($this->targetStatus), $this->statusReason, auth()->user()), 'Tenant status updated.')) {
            $this->showStatus = false;
            $this->tenant->refresh();
        }
    }

    public function resetAdminPassword(TenantAdministration $admin): void
    {
        $this->need('platform.tenants.manage');
        $result = $this->attempt(fn () => $admin->resetAdminPassword($this->tenant, auth()->user()), 'Temporary password generated. It is shown once.');
        if (is_array($result)) {
            $this->newCredentials = $result;
        }
    }

    public function render(PlatformMetrics $metrics, TenantAdministration $admin)
    {
        return view('livewire.platform.tenants.show', [
            'health' => $metrics->tenantHealth()[$this->tenant->id] ?? null,
            'admins' => $admin->admins($this->tenant),
            'matrix' => $metrics->configMatrix()[$this->tenant->id] ?? [],
            'audit' => \App\Models\AuditLog::where('tenant_id', $this->tenant->id)->where('action', 'like', 'platform.%')->with('actor')->latest('id')->take(15)->get(),
        ]);
    }
}
