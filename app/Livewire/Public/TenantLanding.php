<?php

namespace App\Livewire\Public;

use App\Models\ContentItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\TenantContext;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Tenant-branded entry point. Records the validated tenant in the session for guest flows. */
#[Layout('layouts.guest')]
class TenantLanding extends Component
{
    public Tenant $tenant;

    public function mount(Tenant $tenant)
    {
        abort_unless($tenant->isActive(), 404);
        if (auth()->check()) {
            return $this->redirect(route('home'));
        }
        session(['entry_tenant_id' => $tenant->id]);
        TenantContext::set($tenant);
        view()->share('currentTenant', $tenant);
    }

    public function render()
    {
        return TenantContext::run($this->tenant, fn () => view('livewire.public.tenant-landing', [
            'products' => Product::with('currentVersion')->where('is_active', true)->where('show_in_catalog', true)->whereNull('parent_id')->orderBy('sort')->get(),
            'faqs' => ContentItem::where('type', 'faq')->where('is_active', true)->orderBy('sort')->take(6)->get(),
            'banner' => ContentItem::where('type', 'banner')->where('is_active', true)->orderBy('sort')->first(),
            'currentTenant' => $this->tenant,
        ]));
    }
}
