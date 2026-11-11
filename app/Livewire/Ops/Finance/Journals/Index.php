<?php

namespace App\Livewire\Ops\Finance\Journals;

use App\Enums\JournalStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\JournalVoucher;
use App\Services\Reports\CsvExport;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.ops')]
class Index extends Component
{
    use HandlesActions, WithDataTable;

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterOrigin = '';

    public string $filterModule = '';

    public string $filterFrom = '';

    public string $filterTo = '';

    public function mount(): void
    {
        $this->need('fms.view');
    }

    #[On('realtime')]
    public function refreshData(): void {}

    private function query(): Builder
    {
        $q = JournalVoucher::query()->with(['creator', 'period'])
            ->when($this->filterStatus, fn ($q, $v) => $q->where('status', $v))
            ->when($this->filterOrigin, fn ($q, $v) => $q->where('origin', $v))
            ->when($this->filterModule, fn ($q, $v) => $q->where('source_module', $v))
            ->when($this->filterFrom, fn ($q, $v) => $q->whereDate('voucher_date', '>=', $v))
            ->when($this->filterTo, fn ($q, $v) => $q->whereDate('voucher_date', '<=', $v));
        $this->applySearch($q, ['voucher_no', 'narration', 'event_type']);

        return $this->applySort($q, 'id', ['id', 'voucher_no', 'voucher_date', 'total_debit', 'status']);
    }

    public function export()
    {
        $this->need('reports.export');
        $rows = $this->query()->limit(5000)->get()->map(fn ($j) => [$j->voucher_no, $j->voucher_date->toDateString(), $j->origin, $j->source_module, $j->event_type,
            $j->narration, $j->status->value, $j->total_debit, $j->total_credit]);

        return CsvExport::download('journal-vouchers', ['Voucher', 'Date', 'Origin', 'Module', 'Event', 'Narration', 'Status', 'Debit', 'Credit'], $rows,
            ['status' => $this->filterStatus, 'from' => $this->filterFrom, 'to' => $this->filterTo]);
    }

    public function render()
    {
        return view('livewire.ops.finance.journals.index', [
            'vouchers' => $this->query()->paginate($this->perPage),
            'statuses' => JournalStatus::options(),
            'modules' => JournalVoucher::query()->whereNotNull('source_module')->distinct()->orderBy('source_module')->pluck('source_module', 'source_module')->all(),
        ]);
    }
}
