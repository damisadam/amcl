<?php

namespace App\Livewire\Ops\Leads;

use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Lead;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Lending\LeadService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Lead pipeline (LMS §6.1). */
#[Layout('layouts.ops')]
class Index extends Component
{
    use HandlesActions, WithDataTable;

    #[Url(except: '')] public string $filterStatus = '';
    #[Url(except: '')] public string $filterChannel = '';
    #[Url(except: '')] public string $filterMine = '';

    public bool $showCreate = false;
    public array $form = ['name' => '', 'mobile' => '', 'cnic' => '', 'channel' => 'branch', 'product_id' => '', 'requested_amount' => '', 'vendor_id' => '', 'notes' => ''];

    public ?int $actionId = null;
    public string $moveTo = '';
    public string $moveNote = '';
    public string $assignTo = '';
    public bool $showMove = false;

    /** M2: human field names in validation messages. */
    protected function validationAttributes(): array
    {
        return ['moveTo' => 'stage', 'moveNote' => 'note', 'assignTo' => 'officer', 'form.name' => 'name', 'form.mobile' => 'mobile number', 'form.cnic' => 'CNIC', 'form.product_id' => 'product', 'form.requested_amount' => 'requested amount', 'form.channel' => 'channel', 'form.vendor_id' => 'vendor', 'form.notes' => 'notes'];
    }

    /** M3: an error disappears as soon as the user edits that field. */
    public function updated(string $property): void
    {
        $this->resetErrorBag($property);
        if (str_starts_with($property, 'filter')) {
            $this->resetPage(); // keep WithDataTable's behaviour
        }
    }

    public function mount(): void
    {
        $this->need('leads.view');
    }

    public function create(LeadService $svc): void
    {
        $this->need('leads.manage');
        $this->validate([
            'form.name' => 'required|string|max:120', 'form.mobile' => ['required', 'regex:/^(\+92|92|0)3\d{9}$/'],
            'form.cnic' => ['nullable', 'regex:/^\d{5}-\d{7}-\d$/'], 'form.channel' => 'required|in:branch,call_center,website,app,vendor,dfs',
            'form.product_id' => 'nullable|integer', 'form.requested_amount' => 'nullable|numeric|min:0', 'form.vendor_id' => 'nullable|integer', 'form.notes' => 'nullable|string|max:1000',
        ], ['form.mobile.regex' => 'Enter a valid Pakistani mobile number, e.g. 03001234567.', 'form.cnic.regex' => 'Enter the CNIC as 12345-1234567-1.']);
        $data = array_map(fn ($v) => $v === '' ? null : $v, $this->form);
        abort_if($data['product_id'] && ! Product::whereKey($data['product_id'])->exists(), 403);
        abort_if($data['vendor_id'] && ! Vendor::whereKey($data['vendor_id'])->exists(), 403);
        if ($this->attempt(fn () => $svc->create($data, auth()->user()), __('Lead captured.'))) {
            $this->showCreate = false;
            $this->reset('form');
        }
    }

    public function openMove(int $id): void
    {
        $this->need('leads.manage');
        $lead = Lead::findOrFail($id);
        $this->actionId = $id;
        $this->moveTo = '';
        $this->moveNote = '';
        $this->assignTo = (string) $lead->assigned_to;
        $this->showMove = true;
    }

    public function move(): void
    {
        $this->need('leads.manage');
        $this->validate(['moveTo' => 'required|in:new,contacted,qualified,dropped', 'moveNote' => 'nullable|string|max:500']);
        if ($this->attempt(fn () => app(LeadService::class)->moveTo(Lead::findOrFail($this->actionId), $this->moveTo, auth()->user(), $this->moveNote), __('Lead updated.'))) {
            $this->showMove = false;
        }
    }

    public function assign(): void
    {
        $this->need('leads.manage');
        $this->validate(['assignTo' => 'required|integer']);
        $to = User::where('type', 'staff')->findOrFail($this->assignTo);
        if ($this->attempt(fn () => app(LeadService::class)->assign(Lead::findOrFail($this->actionId), $to, auth()->user()), __('Lead assigned.'))) {
            $this->showMove = false;
        }
    }

    public function convert(int $id)
    {
        $this->need('leads.manage');
        $this->need('applications.create');
        $app = $this->attempt(fn () => app(LeadService::class)->convert(Lead::findOrFail($id), auth()->user()), __('Lead converted to a draft application.'));
        if ($app) {
            return $this->redirect(route('ops.applications.show', $app), navigate: true);
        }
    }

    public function render()
    {
        $q = Lead::with(['product', 'assignee', 'branch']);
        $q = $this->applySearch($q, ['name', 'mobile', 'lead_no', 'cnic'])
            ->when($this->filterStatus, fn ($w) => $w->where('status', $this->filterStatus))
            ->when($this->filterChannel, fn ($w) => $w->where('channel', $this->filterChannel))
            ->when($this->filterMine, fn ($w) => $w->where('assigned_to', auth()->id()));

        return view('livewire.ops.leads.index', [
            'leads' => $this->applySort($q, 'id', ['lead_no', 'name', 'created_at', 'requested_amount'])->paginate($this->perPage),
            'pipeline' => Lead::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
            'products' => Product::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'vendors' => Vendor::where('status', 'active')->orderBy('business_name')->pluck('business_name', 'id')->all(),
            'staff' => User::where('type', 'staff')->where('status', 'active')->whereHas('roles.permissions', fn ($p) => $p->where('key', 'leads.manage'))->orderBy('name')->pluck('name', 'id')->all(),
            'channels' => ['branch' => 'Branch walk-in', 'call_center' => 'Call centre', 'website' => 'Website', 'app' => 'Mobile / web app', 'vendor' => 'Vendor referral', 'dfs' => 'DFS channel'],
        ]);
    }
}
