<?php

namespace App\Livewire\Ops\Payments;

use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentPurpose;
use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\PaymentCallback;
use App\Models\PaymentOrder;
use App\Support\Money;
use App\Support\TenantContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Payment orders (all external money movement) and the provider callback inbox. */
#[Layout('layouts.ops')]
class Index extends Component
{
    use HandlesActions, WithDataTable;

    #[Url(except: 'orders')]
    public string $tab = 'orders';

    #[Url(except: '')]
    public string $filterPurpose = '';

    #[Url(except: '')]
    public string $filterProvider = '';

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterRecon = '';

    #[Url(except: '')]
    public string $filterResult = '';

    public function mount(): void
    {
        $this->need('payments.view');
    }

    #[On('realtime')]
    public function refreshList(): void {}

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $this->need('payments.view');
        $data = ['purposes' => PaymentPurpose::options(), 'statuses' => PaymentOrderStatus::options(),
            'providers' => collect(config('integrations.providers'))->where('category', 'payments')->map(fn ($p) => $p['name'])->all()];

        if ($this->tab === 'callbacks') {
            $q = PaymentCallback::query()->with('paymentOrder')->where('tenant_id', TenantContext::id())
                ->when($this->filterProvider, fn ($q) => $q->where('provider', $this->filterProvider))
                ->when($this->filterResult, fn ($q) => $q->where('result', $this->filterResult));
            $this->applySearch($q, ['event_id', 'provider_ref']);
            $data['callbacks'] = $q->latest('id')->paginate($this->perPage);
        } else {
            $q = PaymentOrder::query()
                ->when($this->filterPurpose, fn ($q) => $q->where('purpose', $this->filterPurpose))
                ->when($this->filterProvider, fn ($q) => $q->where('provider', $this->filterProvider))
                ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
                ->when($this->filterRecon, fn ($q) => $q->where('reconciliation_status', $this->filterRecon));
            $this->applySearch($q, ['reference', 'provider_ref', 'counterparty_name']);
            $s = (clone $q)->reorder()->selectRaw("COUNT(*) c, COALESCE(SUM(CASE WHEN status='succeeded' THEN amount END),0) ok,
                SUM(status IN ('created','pending','processing','timed_out')) open, SUM(status='failed') f, SUM(reconciliation_status='exception') ex")->first();
            $data['orders'] = $this->applySort($q, 'id', ['id', 'amount', 'status', 'created_at'])->paginate($this->perPage);
            $data['sums'] = ['count' => (int) $s->c, 'succeeded' => Money::of($s->ok), 'open' => (int) $s->open, 'failed' => (int) $s->f, 'exceptions' => (int) $s->ex];
        }

        return view('livewire.ops.payments.index', $data)->title(__('Payment orders'));
    }
}
