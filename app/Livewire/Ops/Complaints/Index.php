<?php

namespace App\Livewire\Ops\Complaints;

use App\Enums\ComplaintCategory;
use App\Enums\ComplaintStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Complaint;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Grievance redressal queue (DFS §5.3) incl. transaction disputes and refund requests. */
#[Layout('layouts.ops')]
class Index extends Component
{
    use HandlesActions, WithDataTable;

    #[Url(except: 'open')]
    public string $filterStatus = 'open';

    #[Url(except: '')]
    public string $filterCategory = '';

    #[Url(except: '')]
    public string $filterPriority = '';

    #[Url(except: false)]
    public bool $filterOverdue = false;

    #[Url(except: false)]
    public bool $filterMine = false;

    public function mount(): void
    {
        $this->need('complaints.view');
    }

    #[On('realtime')]
    public function refreshList(): void {}

    public function render()
    {
        $this->need('complaints.view');
        $closed = [ComplaintStatus::Resolved->value, ComplaintStatus::Closed->value];
        $q = Complaint::query()->with(['customer', 'assignee'])
            ->when($this->filterStatus === 'open', fn ($q) => $q->whereNotIn('status', $closed))
            ->when($this->filterStatus && $this->filterStatus !== 'open', fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterCategory, fn ($q) => $q->where('category', $this->filterCategory))
            ->when($this->filterPriority, fn ($q) => $q->where('priority', $this->filterPriority))
            ->when($this->filterOverdue, fn ($q) => $q->where('sla_due_at', '<', now())->whereNotIn('status', $closed))
            ->when($this->filterMine, fn ($q) => $q->where('assigned_to', auth()->id()));
        $this->applySearch($q, ['ticket_no', 'subject']);
        $counts = Complaint::query()->selectRaw("SUM(status NOT IN ('resolved','closed')) open, SUM(sla_due_at < NOW() AND status NOT IN ('resolved','closed')) overdue,
            SUM(category IN ('transaction_dispute','refund_request') AND status NOT IN ('resolved','closed')) disputes, SUM(status='escalated') escalated")->first();

        return view('livewire.ops.complaints.index', [
            'rows' => $this->applySort($q, 'id', ['id', 'sla_due_at', 'created_at', 'priority'])->paginate($this->perPage),
            'statuses' => ComplaintStatus::options(),
            'categories' => ComplaintCategory::options(),
            'counts' => $counts,
        ])->title(__('Complaints'));
    }
}
