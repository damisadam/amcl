<?php

namespace App\Livewire\Ops\Customers;

use App\Enums\AmlStatus;
use App\Enums\CustomerStatus;
use App\Enums\KycStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Branch;
use App\Models\Customer;
use App\Services\Audit\Auditor;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.ops')]
class Index extends Component
{
    use HandlesActions, WithDataTable;

    #[Url(except: '')] public string $filterKyc = '';
    #[Url(except: '')] public string $filterAml = '';
    #[Url(except: '')] public string $filterStatus = '';
    #[Url(except: '')] public string $filterBranch = '';
    #[Url(except: '')] public string $filterChannel = '';

    /** Customer ids whose CNIC / mobile the user chose to reveal (each reveal is audited). */
    public array $revealed = [];

    public function mount(): void
    {
        $this->need('customers.view');
    }

    #[On('realtime')]
    public function refreshList(): void {}

    /** M13: branch roles without "view all" see only their branch's customers (or ones assigned to them). */
    public static function scopeForUser($q, $u)
    {
        if ($u->hasPermission('applications.view_all') || ! $u->branch_id || $u->branch?->is_head_office) {
            return $q;
        }

        return $q->where(fn ($w) => $w->where('branch_id', $u->branch_id)->orWhere('assigned_officer_id', $u->id));
    }

    public function reveal(int $id): void
    {
        $this->need('customers.view_sensitive');
        $c = self::scopeForUser(Customer::query(), auth()->user())->findOrFail($id);
        Auditor::record('customer.sensitive_revealed', $c, [], ['fields' => ['cnic', 'mobile'], 'screen' => 'customers.index']);
        $this->revealed[] = $c->id;
    }

    private function query()
    {
        $u = auth()->user();
        $q = self::scopeForUser(Customer::query(), $u)->with(['branch', 'wallet'])->withCount(['applications', 'loanAccounts']);
        $q = $this->applySearch($q, ['full_name', 'cif_no', 'mobile', 'cnic', 'email']);

        return $q->when($this->filterKyc, fn ($w) => $w->where('kyc_status', $this->filterKyc))
            ->when($this->filterAml && $u->hasPermission('aml.view'), fn ($w) => $w->where('aml_status', $this->filterAml))
            ->when($this->filterStatus, fn ($w) => $w->where('status', $this->filterStatus))
            ->when($this->filterBranch, fn ($w) => $w->where('branch_id', $this->filterBranch))
            ->when($this->filterChannel, fn ($w) => $w->where('onboarding_channel', $this->filterChannel));
    }

    public function export()
    {
        $this->need('customers.export');
        $sensitive = auth()->user()->hasPermission('customers.view_sensitive');
        Auditor::record('customers.exported', null, [], ['filters' => array_filter([$this->search, $this->filterKyc, $this->filterStatus, $this->filterBranch])]);
        $rows = $this->query()->orderBy('cif_no')->get();

        return response()->streamDownload(function () use ($rows, $sensitive) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['CIF', 'Name', 'CNIC', 'Mobile', 'City', 'Branch', 'KYC', 'Status', 'Risk', 'Applications', 'Loans', 'Onboarded']);
            foreach ($rows as $c) {
                fputcsv($out, [$c->cif_no, $c->full_name, $sensitive ? $c->cnic : $c->maskedCnic(), $sensitive ? $c->mobile : substr($c->mobile, 0, 4).'*****'.substr($c->mobile, -2),
                    $c->city, $c->branch?->name, $c->kyc_status->label(), $c->status->label(), $c->risk_rating, $c->applications_count, $c->loan_accounts_count, $c->created_at->toDateString()]);
            }
            fclose($out);
        }, 'customers-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function render()
    {
        return view('livewire.ops.customers.index', [
            'customers' => $this->applySort($this->query(), 'id', ['cif_no', 'full_name', 'created_at', 'kyc_status'])->paginate($this->perPage),
            'branches' => Branch::orderBy('name')->pluck('name', 'id')->all(),
            'revealedIds' => array_flip($this->revealed),
            'kycOptions' => KycStatus::options(),
            'amlOptions' => AmlStatus::options(),
            'statusOptions' => CustomerStatus::options(),
            'sensitive' => auth()->user()->hasPermission('customers.view_sensitive'),
            'canAml' => auth()->user()->hasPermission('aml.view'),
        ]);
    }
}
