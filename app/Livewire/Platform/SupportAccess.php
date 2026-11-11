<?php

namespace App\Livewire\Platform;

use App\Livewire\Concerns\HandlesActions;
use App\Models\SupportAccessGrant;
use App\Models\Tenant;
use App\Services\Platform\SupportAccessService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Time-limited, justified, dual-controlled support access to a tenant (D-005). */
#[Layout('layouts.platform')]
class SupportAccess extends Component
{
    use HandlesActions;

    public string $tenantId = '';

    public string $reason = '';

    public string $ticket = '';

    public int $hours = 4;

    public ?int $viewGrant = null;

    public ?array $summary = null;

    public function mount(): void
    {
        $this->need('platform.support_access.request', 'platform.support_access.grant');
    }

    public function requestAccess(SupportAccessService $svc): void
    {
        $this->need('platform.support_access.request');
        $this->validate(['tenantId' => 'required|exists:tenants,id', 'reason' => 'required|string|min:15|max:500', 'ticket' => 'nullable|string|max:60', 'hours' => 'required|integer|min:1|max:24']);
        if ($this->attempt(fn () => $svc->request(Tenant::findOrFail($this->tenantId), auth()->user(), $this->reason, $this->ticket ?: null, $this->hours), 'Access request submitted for approval.')) {
            $this->reset('tenantId', 'reason', 'ticket');
        }
    }

    public function grant(int $id, SupportAccessService $svc): void
    {
        $this->need('platform.support_access.grant');
        $this->attempt(fn () => $svc->grant(SupportAccessGrant::findOrFail($id), auth()->user()), 'Access granted.');
    }

    public function revoke(int $id, SupportAccessService $svc): void
    {
        $this->need('platform.support_access.request', 'platform.support_access.grant');
        $this->attempt(fn () => $svc->revoke(SupportAccessGrant::findOrFail($id), auth()->user(), 'Revoked from support access screen'), 'Access revoked.');
        if ($this->viewGrant === $id) {
            $this->viewGrant = null;
            $this->summary = null;
        }
    }

    public function openView(int $id, SupportAccessService $svc): void
    {
        $this->need('platform.support_access.request');
        $g = SupportAccessGrant::findOrFail($id);
        $summary = $this->attempt(fn () => $svc->supportView($g, auth()->user()));
        if (is_array($summary)) {
            $this->viewGrant = $id;
            $this->summary = $summary;
        }
    }

    public function render(SupportAccessService $svc)
    {
        $grants = SupportAccessGrant::with(['tenant', 'platformUser', 'grantor'])->latest('id')->take(100)->get();

        return view('livewire.platform.support-access', [
            'grants' => $grants->map(fn ($g) => ['g' => $g, 'status' => $svc->status($g)]),
            'tenants' => Tenant::where('status', 'active')->orderBy('name')->pluck('name', 'id'),
            'viewTenant' => $this->viewGrant ? SupportAccessGrant::find($this->viewGrant)?->tenant : null,
        ]);
    }
}
